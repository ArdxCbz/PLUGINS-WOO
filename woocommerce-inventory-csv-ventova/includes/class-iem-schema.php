<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Esquema de tablas propias del plugin (v3.0+).
 *
 * Ocho tablas:
 *  - {prefix}iem_count_sessions: encabezado del conteo de un mes/sucursal.
 *  - {prefix}iem_count_lines:    líneas (producto contado) de una sesión.
 *  - {prefix}iem_mermas:         registro de mermas / defectuosos.
 *  - {prefix}iem_suppliers       (1.4+): proveedores.
 *  - {prefix}iem_purchases       (1.4+): cabecera de compra.
 *  - {prefix}iem_purchase_lines  (1.4+): líneas de compra (2.1+: `supplier_id` por línea).
 *  - {prefix}iem_purchase_line_receipts (2.1+): qué variación recibió cuánto por línea.
 *  - {prefix}iem_kardex          (1.5+): movimientos de inventario (running balance).
 *
 * Estrategia de versionado: si `iem_db_version` (option) difiere de DB_VERSION,
 * se ejecuta install() y se sube la opción. install() usa dbDelta, por lo que
 * es idempotente y soporta ALTERs incrementales en versiones futuras.
 */
class IEM_Schema
{
    const DB_VERSION = '2.7';
    const OPTION_KEY = 'iem_db_version';

    public static function table($name)
    {
        global $wpdb;
        return $wpdb->prefix . 'iem_' . $name;
    }

    public static function maybe_upgrade()
    {
        $current = (string) get_option(self::OPTION_KEY, '');
        if ($current === self::DB_VERSION) {
            return;
        }

        // Migración 1.2 → 1.3: las filas "extra" (item_id=0) rompen el UNIQUE
        // (session_id, item_id) original. dbDelta no convierte UNIQUE → KEY,
        // así que el DROP se hace explícito antes de re-correr install().
        if ($current !== '' && version_compare($current, '1.3', '<')) {
            global $wpdb;
            $lines = self::table('count_lines');
            // Suppress: si la tabla aún no existe (instalación fresca), se ignora.
            $wpdb->hide_errors();
            $wpdb->query("ALTER TABLE $lines DROP INDEX session_item");
            $wpdb->show_errors();
        }

        // Migración 1.5 → 1.6: rediseño de proveedores.
        //  - Nombre pasa a representar la persona de contacto (lo que antes
        //    era `contact_name`).
        //  - Se agrega columna `company` con la razón social (lo que antes
        //    era `name`).
        //  - Se eliminan `tax_id` (NIT) y `contact_name`.
        // El ALTER se hace ANTES de install() para mover los datos antes de
        // que dbDelta intente dropear columnas (cosa que en realidad no hace
        // por sí solo, pero el orden mantiene la intención clara).
        if ($current !== '' && version_compare($current, '1.6', '<')) {
            self::migrate_suppliers_1_5_to_1_6();
        }

        // Migración 1.6 → 1.7: las compras pasan de referenciar variaciones a
        // referenciar productos padre. Agregamos `received_variation_id`
        // (la variación elegida al recibir) en purchase_lines. Las compras
        // ya recibidas quedan con 0 (no se vuelven a tocar; su stock ya entró
        // sobre la variación que estaba en item_id antes del rediseño).
        if ($current !== '' && version_compare($current, '1.7', '<')) {
            self::migrate_purchase_lines_1_6_to_1_7();
        }

        // Migración 2.0 → 2.1: proveedor por línea + recepción por variación.
        //  - `purchase_lines.supplier_id`: nuevo. Backfill con el proveedor de la
        //    cabecera para que las compras viejas conserven proveedor por línea.
        //  - `iem_purchase_line_receipts`: la crea install() (dbDelta). Tras
        //    install() se backfillea desde las líneas ya recibidas para preservar
        //    "última compra" y reportes por variación.
        if ($current !== '' && version_compare($current, '2.1', '<')) {
            self::migrate_purchase_lines_2_0_to_2_1();
        }

        self::install();

        // Backfill de recepciones: una fila por línea recibida (idempotente).
        if ($current !== '' && version_compare($current, '2.1', '<')) {
            self::backfill_receipts_2_1();
        }

        // Migración 2.4 → 2.5: rellenar amount_bob/amount_usd/tc en los gastos de
        // importación previos a la moneda dual. dbDelta ya agregó las columnas
        // (en 0); este backfill las completa a partir de amount/fin_currency/fin_rate.
        if ($current !== '' && version_compare($current, '2.5', '<')) {
            self::backfill_purchase_expenses_dual_2_5();
        }

        // Migración 2.5 → 2.6: el registro de un gasto pasa a separarse de su egreso
        // (registrar → validar). Los gastos previos ya tenían su egreso posteado al
        // crearse, así que cuentan como YA validados: se les marca `validated_at`
        // con su `created_at` (solo los `active` sin fecha). dbDelta ya agregó la
        // columna `validated_at` y `purchases.inventory_affected` (default 1).
        if ($current !== '' && version_compare($current, '2.6', '<')) {
            self::backfill_expense_validated_2_6();
        }

        // Migración 1.4 → 1.5: backfill del kardex con stock actual de WC.
        // Idempotente (NOT EXISTS) por si la migración se reejecuta. También
        // se dispara en instalación fresca: si hay productos con stock al
        // momento de instalar, se siembra el kardex con apertura.
        if (version_compare($current, '1.5', '<')) {
            self::backfill_opening();
        }

        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    /**
     * Migración 1.5 → 1.6 del esquema de proveedores.
     *
     * Estado previo: (name=razón social, tax_id=NIT, contact_name=persona).
     * Estado nuevo:  (name=persona, company=razón social) sin NIT ni contact_name.
     *
     * Estrategia:
     *  1. Agregar columna `company` (idempotente).
     *  2. Copiar `name` → `company` solo si `company` está vacío (preserva razón social).
     *  3. Copiar `contact_name` → `name` solo si `contact_name` no estaba vacío
     *     (caso: ya había una persona registrada; el contenido viejo de `name`,
     *     que era la razón social, ya quedó en `company` en el paso anterior).
     *  4. Dropear `tax_id` y `contact_name` si todavía existen.
     *
     * Todo el bloque es idempotente: si las columnas ya no existen o `company`
     * ya está, se omite con `hide_errors`. Si la tabla aún no existe (instalación
     * fresca) los ALTER fallan en silencio y el CREATE TABLE de install() crea
     * la versión nueva directamente.
     */
    public static function migrate_suppliers_1_5_to_1_6()
    {
        global $wpdb;
        $t = self::table('suppliers');

        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) {
            return;
        }

        $cols = $wpdb->get_col("SHOW COLUMNS FROM $t", 0);
        $has_company      = in_array('company',      $cols, true);
        $has_tax_id       = in_array('tax_id',       $cols, true);
        $has_contact_name = in_array('contact_name', $cols, true);

        $wpdb->hide_errors();

        if (!$has_company) {
            $wpdb->query("ALTER TABLE $t ADD COLUMN company VARCHAR(150) NOT NULL DEFAULT '' AFTER name");
            $wpdb->query("UPDATE $t SET company = name WHERE company = '' AND name <> ''");
        }

        if ($has_contact_name) {
            // Si el viejo contact_name traía la persona, esa es la que pasa a `name`.
            // Si estaba vacío, dejamos `name` como estaba (en ese caso la razón social
            // ya fue copiada a `company`; el operador deberá completar la persona).
            $wpdb->query("UPDATE $t SET name = contact_name WHERE contact_name <> ''");
            $wpdb->query("ALTER TABLE $t DROP COLUMN contact_name");
        }

        if ($has_tax_id) {
            $wpdb->query("ALTER TABLE $t DROP COLUMN tax_id");
        }

        $wpdb->show_errors();
    }

    /**
     * Migración 1.6 → 1.7 del esquema de líneas de compra.
     *
     * Agrega `received_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0`.
     * Es la variación elegida al recibir (cuando el padre tiene variaciones).
     * Para productos simples queda en 0 (su `item_id` ya es el destino del stock).
     *
     * Idempotente: si la columna ya existe se omite. Si la tabla aún no existe
     * (instalación fresca) el CREATE TABLE de install() la crea directamente.
     */
    public static function migrate_purchase_lines_1_6_to_1_7()
    {
        global $wpdb;
        $t = self::table('purchase_lines');

        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) {
            return;
        }

        $cols = $wpdb->get_col("SHOW COLUMNS FROM $t", 0);
        if (in_array('received_variation_id', $cols, true)) {
            return;
        }

        $wpdb->hide_errors();
        $wpdb->query(
            "ALTER TABLE $t
             ADD COLUMN received_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER parent_id"
        );
        $wpdb->show_errors();
    }

    /**
     * Siembra movimientos `opening` en el kardex para cada item con stock > 0
     * que aún no tenga ningún movimiento. Una sola query SQL, ~O(N).
     *
     * 3.21+: excluye **productos padre variables** (que tienen stock derivado
     * = Σ variaciones, calculado por el child theme). El kardex es ledger por
     * cosa con stock real (variación o simple); el padre nunca entra.
     *
     * 3.27+: tras el INSERT masivo (que deja `sucursal_slug=''` porque la regla
     * de derivación es PHP, no SQL), un pase resuelve la sucursal de cada fila
     * `opening` recién creada con `IEM_Sucursales::resolve_product_sucursal_slug()`,
     * la misma fuente de verdad que usan los movimientos en vivo. Sin esto, el
     * stock inicial (sobre todo el que no rota) no aparecía bajo el filtro de
     * sucursal del kardex hasta su primer movimiento real. El pase es idempotente:
     * solo toca filas `opening` con slug vacío y solo cuando la resolución da
     * un slug no vacío.
     */
    public static function backfill_opening()
    {
        global $wpdb;
        $kardex = self::table('kardex');
        if ($wpdb->get_var("SHOW TABLES LIKE '$kardex'") !== $kardex) {
            return; // Defensa: si install no la creó por alguna razón, no romper.
        }
        $now = current_time('mysql');
        // El filtro de "no es padre variable" se resuelve por taxonomía
        // `product_type`: si el producto está en el término con slug='variable',
        // se excluye. Variaciones (post_type='product_variation') y simples
        // (no en 'variable') sí entran.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $kardex
              (item_id, parent_id, sucursal_slug, movement_date, type, direction, qty,
               unit_cost, balance_after, ref_table, ref_id, ref_code, user_id, notes, created_at)
            SELECT
              p.ID,
              CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE 0 END,
              '',
              %s, 'opening', 'I',
              CAST(m.meta_value AS SIGNED),
              NULL,
              CAST(m.meta_value AS SIGNED),
              NULL, NULL, NULL,
              0,
              'Apertura de kardex al instalar v1.5',
              %s
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_stock'
            WHERE p.post_type IN ('product','product_variation')
              AND p.post_status = 'publish'
              AND CAST(m.meta_value AS SIGNED) > 0
              AND NOT EXISTS (
                  SELECT 1 FROM $kardex k WHERE k.item_id = p.ID
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM {$wpdb->term_relationships} tr
                  JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                  JOIN {$wpdb->terms} t          ON tt.term_id = t.term_id
                  WHERE tr.object_id = p.ID
                    AND tt.taxonomy  = 'product_type'
                    AND t.slug       = 'variable'
              )",
            $now, $now
        ));

        self::backfill_opening_sucursales();
    }

    /**
     * Resuelve `sucursal_slug` en las filas `opening` que quedaron en '' tras el
     * INSERT masivo de backfill_opening(). Usa la misma regla que los movimientos
     * en vivo (IEM_Sucursales) para que el stock inicial sea visible bajo el
     * filtro de sucursal del kardex. Idempotente y seguro de reejecutar: solo
     * actualiza filas con slug vacío y solo si la resolución da un slug no vacío;
     * nunca toca `balance_after` (el saldo no depende de la sucursal).
     */
    public static function backfill_opening_sucursales()
    {
        global $wpdb;

        if (!class_exists('IEM_Sucursales') || !function_exists('wc_get_product')) {
            return; // WooCommerce o el helper no disponibles: no romper.
        }

        $kardex = self::table('kardex');
        $rows = $wpdb->get_results(
            "SELECT id, item_id FROM $kardex
             WHERE type = 'opening' AND sucursal_slug = ''",
            ARRAY_A
        );
        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $product = wc_get_product((int) $row['item_id']);
            if (!$product) {
                continue;
            }
            $slug = IEM_Sucursales::resolve_product_sucursal_slug($product);
            if ($slug === '') {
                continue;
            }
            $wpdb->update(
                $kardex,
                ['sucursal_slug' => $slug],
                ['id' => (int) $row['id']],
                ['%s'],
                ['%d']
            );
        }
    }

    public static function install()
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;
        $charset       = $wpdb->get_charset_collate();
        $sessions      = self::table('count_sessions');
        $lines         = self::table('count_lines');
        $mermas        = self::table('mermas');
        $suppliers     = self::table('suppliers');
        $purchases     = self::table('purchases');
        $purch_lines   = self::table('purchase_lines');
        $kardex        = self::table('kardex');

        // dbDelta es muy estricto con la sintaxis: doble espacio antes de las
        // paréntesis de PRIMARY KEY, mayúsculas en tipos, etc. No reformatear.
        $sql_sessions = "CREATE TABLE $sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            period CHAR(7) NOT NULL,
            sucursal_slug VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            closed_by BIGINT UNSIGNED DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            notes TEXT,
            PRIMARY KEY  (id),
            UNIQUE KEY period_sucursal (period, sucursal_slug),
            KEY status_key (status)
        ) $charset;";

        // Notas v1.3:
        //  - `is_extra` distingue filas ad-hoc (agregadas por el contador para
        //    productos físicos que no estaban en el listado del snapshot) de
        //    las filas originales (`is_extra=0`).
        //  - `notes` es texto libre opcional para esas filas.
        //  - Se removió el UNIQUE (session_id, item_id) porque las filas extra
        //    pueden tener item_id=0 múltiples veces. Queda solo como KEY no
        //    única para conservar el índice.
        $sql_lines = "CREATE TABLE $lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(100) NOT NULL DEFAULT '',
            name TEXT,
            category TEXT,
            sucursal_slug VARCHAR(100) NOT NULL,
            stock_at_count INT NOT NULL DEFAULT 0,
            counted_qty INT DEFAULT NULL,
            status_at_count VARCHAR(20) NOT NULL DEFAULT 'Sin conteo',
            is_extra TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY session_item (session_id, item_id),
            KEY item_id_key (item_id),
            KEY is_extra_key (is_extra)
        ) $charset;";

        $sql_mermas = "CREATE TABLE $mermas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(100) NOT NULL DEFAULT '',
            name TEXT,
            sucursal_slug VARCHAR(100) NOT NULL,
            qty INT NOT NULL DEFAULT 0,
            tipo VARCHAR(30) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            session_id BIGINT UNSIGNED DEFAULT NULL,
            decremented_wc TINYINT(1) NOT NULL DEFAULT 0,
            cost_at_register DECIMAL(12,2) DEFAULT NULL,
            notes TEXT,
            returned_at DATETIME DEFAULT NULL,
            returned_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY sucursal_date (sucursal_slug, created_at),
            KEY item_id_key (item_id),
            KEY tipo_key (tipo),
            KEY returned_at_key (returned_at)
        ) $charset;";

        // ── 1.4+: proveedores y compras ──────────────────────────────────────
        // Notas de diseño:
        //  - `suppliers.active=0` actúa como soft-disable (no se borra para no
        //    romper FKs lógicas hacia `purchases.supplier_id`).
        //  - `purchases.code` es UNIQUE y se autogenera tipo "COMP-YYYY-NNNN"
        //    en IEM_Purchases, pero es editable mientras la compra esté en draft.
        //  - `purchases.status` solo tiene dos valores: 'draft' / 'received'.
        //    La transición a received es atómica (suma stock + actualiza costo
        //    de cada variación) y NO es reversible.
        //  - 2.3+: datos logísticos de la importación a nivel cabecera —
        //    `package_count` (bultos), `gross_weight_kg` (peso bruto Kg),
        //    `cbm_m3` (volumen m³) y `shipping_via` ('' | 'aerea' | 'maritima').
        //    dbDelta los agrega con su DEFAULT; las compras viejas quedan en 0/''.
        //  - 2.7+: `tracking_number` (Nº de tracking alfanumérico) y `eta_date`
        //    (fecha estimada de arribo, DATE NULL). dbDelta los agrega; compras
        //    viejas quedan en ''/NULL.
        //  - `purchase_lines.line_total` es denormalizado para listados sin
        //    SUM(qty*unit_cost) por compra. Se recalcula en cada save_line.
        // 1.6+: campos = name (persona), company (razón social), phone, email,
        // address, notes, active. NIT y contact_name fueron retirados.
        $sql_suppliers = "CREATE TABLE $suppliers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL,
            company VARCHAR(150) NOT NULL DEFAULT '',
            phone VARCHAR(50) NOT NULL DEFAULT '',
            email VARCHAR(150) NOT NULL DEFAULT '',
            address TEXT,
            notes TEXT,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY active_key (active),
            KEY name_key (name),
            KEY company_key (company)
        ) $charset;";

        $sql_purchases = "CREATE TABLE $purchases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(50) NOT NULL,
            supplier_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sucursal_slug VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            purchase_date DATE NOT NULL,
            received_date DATE DEFAULT NULL,
            invoice_number VARCHAR(50) NOT NULL DEFAULT '',
            total DECIMAL(14,2) NOT NULL DEFAULT 0,
            package_count INT NOT NULL DEFAULT 0,
            gross_weight_kg DECIMAL(12,2) NOT NULL DEFAULT 0,
            cbm_m3 DECIMAL(12,4) NOT NULL DEFAULT 0,
            shipping_via VARCHAR(20) NOT NULL DEFAULT '',
            inventory_affected TINYINT(1) NOT NULL DEFAULT 1,
            tracking_number VARCHAR(100) NOT NULL DEFAULT '',
            eta_date DATE DEFAULT NULL,
            notes TEXT,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            received_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            received_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code_unique (code),
            KEY supplier_key (supplier_id),
            KEY status_key (status),
            KEY sucursal_date (sucursal_slug, purchase_date),
            KEY purchase_date_key (purchase_date)
        ) $charset;";

        // 1.7+: `item_id` ahora siempre apunta al **producto padre** (simple o
        // variable). `received_variation_id` se llena al recibir la compra con
        // la variación destino (0 para productos simples, que reciben sobre
        // el propio item_id). `parent_id` queda en 0 desde 1.7 (vestigio).
        // 1.9+: `origin_cost` = "Valor Exw USD" (meta `costo_de_origen` del
        // producto padre). Es un costo de ORIGEN en USD, paralelo a `unit_cost`
        // (costo de compra local, Bs). Se captura por línea, se snapshotea aquí
        // y al recibir se vuelca al meta del producto padre. NO entra en
        // `line_total` ni en el `total` de la compra (que siguen siendo en Bs).
        // 2.1+: `supplier_id` por línea (proveedor del producto). La cabecera
        // `purchases.supplier_id` pasa a ser proveedor por defecto (opcional) que
        // pre-rellena las líneas; el real para reportes "mejor precio por
        // proveedor" es el de cada línea.
        $sql_purchase_lines = "CREATE TABLE $purch_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            purchase_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            supplier_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            received_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(100) NOT NULL DEFAULT '',
            name TEXT,
            qty INT NOT NULL DEFAULT 0,
            unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
            origin_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
            line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY purchase_key (purchase_id),
            KEY item_id_key (item_id),
            KEY supplier_key (supplier_id),
            KEY received_variation_key (received_variation_id)
        ) $charset;";

        // 2.1+: recepciones por línea. Una fila por (línea, variación destino)
        // con la cantidad que entró a esa variación. Para líneas sin división
        // (simples o una sola variación) hay una sola fila. Para variables con
        // color, varias filas cuya suma de qty = qty de la línea. Es la fuente
        // canónica de "qué variación recibió cuánto" (kardex, última compra,
        // reportes por variación).
        $receipts = self::table('purchase_line_receipts');
        $sql_receipts = "CREATE TABLE $receipts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            purchase_id BIGINT UNSIGNED NOT NULL,
            line_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sucursal_slug VARCHAR(100) NOT NULL DEFAULT '',
            qty INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY purchase_key (purchase_id),
            KEY line_key (line_id),
            KEY variation_key (variation_id)
        ) $charset;";

        // ── 1.5+: Kardex ─────────────────────────────────────────────────────
        // Notas de diseño:
        //  - Una sola tabla central de movimientos. `balance_after` es running
        //    balance del `item_id` y se calcula al insertar leyendo el último
        //    movimiento del mismo item.
        //  - `type` cubre 11 fuentes: opening, purchase, sale, sale_refund,
        //    merma, merma_return, manual_in, manual_out, manual_wc,
        //    transfer_out, transfer_in.
        //  - `unit_cost` NULL para movimientos sin costo conocido (manual, opening).
        //  - `origin_cost` (2.0+): snapshot del "Valor Exw USD" (costo de origen)
        //    al momento del movimiento. Lo llena `purchase` al recibir; NULL en
        //    el resto. Permite ver la evolución del costo de origen compra a
        //    compra en la vista de movimientos del item.
        //  - `ref_table` / `ref_id` / `ref_code` son la traza al documento origen.
        //  - `notes` obligatorio en manual_in/manual_out, opcional en el resto.
        $sql_kardex = "CREATE TABLE $kardex (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sucursal_slug VARCHAR(100) NOT NULL DEFAULT '',
            movement_date DATETIME NOT NULL,
            type VARCHAR(30) NOT NULL,
            direction CHAR(1) NOT NULL,
            qty INT NOT NULL DEFAULT 0,
            unit_cost DECIMAL(12,4) DEFAULT NULL,
            origin_cost DECIMAL(12,4) DEFAULT NULL,
            balance_after INT NOT NULL DEFAULT 0,
            ref_table VARCHAR(50) DEFAULT NULL,
            ref_id BIGINT UNSIGNED DEFAULT NULL,
            ref_code VARCHAR(50) DEFAULT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            notes TEXT,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY item_chrono (item_id, id),
            KEY sucursal_date (sucursal_slug, movement_date),
            KEY type_date (type, movement_date),
            KEY ref_lookup (ref_table, ref_id)
        ) $charset;";

        // ── 2.2+: gastos de importación (integración con Finanzas) ──────────
        // Catálogo de motivos de gasto de importación (flete, aduana, mercadería…).
        $exp_types = self::table('import_expense_types');
        $sql_exp_types = "CREATE TABLE $exp_types (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL DEFAULT '',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id)
        ) $charset;";

        // Gastos adjuntados a una compra. Cada uno dispara un egreso en Finanzas
        // contra la caja ELEGIDA POR GASTO (`fin_account_id`); `fin_movement_id`
        // guarda el id del movimiento para poder reversarlo (contrasiento) si el
        // gasto se edita/elimina.
        // 2.6+: el registro del gasto se SEPARA del egreso. `status`:
        //   - `pending`  → registrado, SIN egreso en Finanzas (fin_movement_id=0).
        //   - `active`   → validado: ya se posteó el egreso (fin_movement_id>0).
        //   - `reversed` → su egreso fue reversado (al editar un validado se reversa
        //                  y se crea uno nuevo).
        // `validated_at` marca cuándo se validó (NULL mientras pending). La validación
        // es un paso manual por registro (botón "Validar"). Los gastos también se
        // pueden registrar/validar DESPUÉS de recibir la compra (pagos posteriores).
        // 2.4+: `amount` está en la MONEDA de la caja elegida. `fin_currency` es
        // esa moneda (código, p.ej. BOB/USD) y `fin_rate` el tipo de cambio
        // (Bs por unidad) capturado al registrar (1 si la caja es base). Permiten
        // mostrar el gasto en su moneda y re-registrar al editar. Las compras y
        // gastos previos a 2.4 quedan en 'BOB'/1 (importes en Bs, comportamiento
        // mono-caja anterior).
        // 2.5+: cada gasto guarda su valor en AMBOS lados de la moneda más el
        // tipo de cambio aplicado, para mostrarlo y editarlo en Bs y en $ a la vez
        // (los USDT tienen muchos decimales, así que el TC se calcula a partir de
        // los dos montos en vez de teclearse a ciegas):
        //  - `amount_bob`: valor del gasto en Bs (siempre).
        //  - `amount_usd`: valor del gasto en USD, 6 decimales (los USDT). 0 si no
        //    se conoce (gastos en Bs sin TC informado).
        //  - `tc`: tipo de cambio Bs por USD (Bs/$). Caja en $ → es el `fin_rate`;
        //    caja en Bs → es solo informativo (no toca el asiento de Finanzas, que
        //    va en Bs con rate=1). 0 si no se informó.
        // `amount` (nativo, lo que se postea a Finanzas) y `fin_rate` (conversión a
        // base que usa Finanzas) se conservan sin cambios. Las filas previas a 2.5
        // se rellenan en migrate/backfill_purchase_expenses_dual_2_5().
        $purch_expenses = self::table('purchase_expenses');
        $sql_purch_expenses = "CREATE TABLE $purch_expenses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            purchase_id BIGINT UNSIGNED NOT NULL,
            type_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(150) NOT NULL DEFAULT '',
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            amount_bob DECIMAL(14,2) NOT NULL DEFAULT 0,
            amount_usd DECIMAL(18,6) NOT NULL DEFAULT 0,
            tc DECIMAL(18,6) NOT NULL DEFAULT 0,
            fin_account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            fin_currency VARCHAR(10) NOT NULL DEFAULT 'BOB',
            fin_rate DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
            fin_movement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            validated_at DATETIME DEFAULT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY purchase_key (purchase_id),
            KEY status_key (status)
        ) $charset;";

        dbDelta($sql_sessions);
        dbDelta($sql_lines);
        dbDelta($sql_mermas);
        dbDelta($sql_suppliers);
        dbDelta($sql_purchases);
        dbDelta($sql_purchase_lines);
        dbDelta($sql_receipts);
        dbDelta($sql_exp_types);
        dbDelta($sql_purch_expenses);
        dbDelta($sql_kardex);
    }

    /**
     * Migración 2.0 → 2.1: agrega `supplier_id` a `purchase_lines` y lo
     * backfillea con el proveedor de la cabecera. Idempotente. La tabla
     * `purchase_line_receipts` la crea install() (dbDelta) y la siembra
     * `backfill_receipts_2_1()`.
     */
    public static function migrate_purchase_lines_2_0_to_2_1()
    {
        global $wpdb;
        $t = self::table('purchase_lines');
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) {
            return; // instalación fresca: install() crea la versión nueva.
        }

        $cols = $wpdb->get_col("SHOW COLUMNS FROM $t", 0);
        $wpdb->hide_errors();
        if (!in_array('supplier_id', $cols, true)) {
            $wpdb->query(
                "ALTER TABLE $t
                 ADD COLUMN supplier_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER parent_id"
            );
            // Backfill: cada línea hereda el proveedor de su cabecera.
            $tp = self::table('purchases');
            $wpdb->query(
                "UPDATE $t pl
                 JOIN $tp p ON p.id = pl.purchase_id
                 SET pl.supplier_id = p.supplier_id
                 WHERE pl.supplier_id = 0"
            );
        }
        $wpdb->show_errors();
    }

    /**
     * Backfill 2.1 de `purchase_line_receipts`: por cada línea de una compra ya
     * recibida, siembra una fila de recepción (variación destino = la única que
     * recibió en el modelo viejo, o el item_id si era simple). Idempotente:
     * omite líneas que ya tengan recepciones.
     */
    public static function backfill_receipts_2_1()
    {
        global $wpdb;
        $tr = self::table('purchase_line_receipts');
        $tl = self::table('purchase_lines');
        $tp = self::table('purchases');
        if ($wpdb->get_var("SHOW TABLES LIKE '$tr'") !== $tr) {
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT pl.id AS line_id, pl.purchase_id, pl.item_id, pl.qty,
                    pl.received_variation_id
             FROM $tl pl
             JOIN $tp p ON p.id = pl.purchase_id
             WHERE p.status = 'received'
               AND NOT EXISTS (SELECT 1 FROM $tr r WHERE r.line_id = pl.id)",
            ARRAY_A
        );
        if (!$rows) {
            return;
        }
        $now = current_time('mysql');
        foreach ($rows as $r) {
            $vid = (int) $r['received_variation_id'] > 0
                ? (int) $r['received_variation_id']
                : (int) $r['item_id'];
            $sucursal = '';
            if (class_exists('IEM_Sucursales')) {
                $p = wc_get_product($vid);
                if ($p) {
                    $sucursal = (string) IEM_Sucursales::resolve_product_sucursal_slug($p);
                }
            }
            $wpdb->insert($tr, [
                'purchase_id'   => (int) $r['purchase_id'],
                'line_id'       => (int) $r['line_id'],
                'variation_id'  => $vid,
                'sucursal_slug' => $sucursal,
                'qty'           => (int) $r['qty'],
                'created_at'    => $now,
            ], ['%d','%d','%d','%s','%d','%s']);
        }
    }

    /**
     * Backfill 2.5 de la moneda dual en `purchase_expenses`. Para cada gasto que
     * todavía no tiene los nuevos campos (amount_bob = amount_usd = 0), deriva
     * sus valores del esquema mono-monto previo:
     *  - Caja en Bs (fin_currency = base 'BOB'): amount_bob = amount; amount_usd
     *    queda en 0 y tc en 0 (esos gastos viejos no tenían referencia en USD).
     *  - Caja en otra moneda (USD…): amount_usd = amount; tc = fin_rate;
     *    amount_bob = amount * fin_rate (su equivalente en Bs al TC registrado).
     * Una sola query, idempotente (el WHERE excluye filas ya migradas o nuevas).
     */
    public static function backfill_purchase_expenses_dual_2_5()
    {
        global $wpdb;
        $t = self::table('purchase_expenses');
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) {
            return;
        }
        $cols = $wpdb->get_col("SHOW COLUMNS FROM $t", 0);
        if (!in_array('amount_bob', $cols, true)
            || !in_array('amount_usd', $cols, true)
            || !in_array('tc', $cols, true)) {
            return; // las columnas aún no existen (no debería: install() corre antes).
        }
        $wpdb->query(
            "UPDATE $t SET
                amount_bob = CASE WHEN fin_currency = 'BOB' THEN amount ELSE ROUND(amount * fin_rate, 2) END,
                amount_usd = CASE WHEN fin_currency = 'BOB' THEN 0 ELSE amount END,
                tc         = CASE WHEN fin_currency = 'BOB' THEN 0 ELSE fin_rate END
             WHERE amount_bob = 0 AND amount_usd = 0 AND tc = 0"
        );
    }

    /**
     * Backfill 2.6: marca como YA validados los gastos previos a la separación
     * registrar→validar. Antes, crear un gasto posteaba el egreso de inmediato; esos
     * gastos están en `active` con `fin_movement_id` > 0, así que se les fija
     * `validated_at = created_at` (solo si está vacío). Idempotente.
     */
    public static function backfill_expense_validated_2_6()
    {
        global $wpdb;
        $t = self::table('purchase_expenses');
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) {
            return;
        }
        $cols = $wpdb->get_col("SHOW COLUMNS FROM $t", 0);
        if (!in_array('validated_at', $cols, true)) {
            return;
        }
        $wpdb->query(
            "UPDATE $t SET validated_at = created_at
             WHERE status = 'active' AND validated_at IS NULL"
        );
    }
}
