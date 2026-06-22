<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Automatizaciones a partir de pedidos de WooCommerce — v1.0.
 *
 * Automatizaciones financieras a partir de pedidos. Dos, ambas opt-in e
 * independientes:
 *
 *  1. DEPÓSITO (ingreso): registra el INGRESO del `_hpos_ardxoz_woo_monto_deposito`
 *     contra una caja configurable, EN EL MOMENTO DEL DEPÓSITO. DEMV avisa con la
 *     action `hawd_deposit_registered` (Express/Admin/Caja) y aquí se reconcilia
 *     el delta (ver `reconcile_deposit_income`); el hook de "completado" queda como
 *     red de seguridad. Antes el ingreso esperaba a "completado", lo que dejaba la
 *     caja descuadrada con el banco mientras el pedido seguía pendiente (típico en
 *     pedidos con efectivo, que se completan al aprobar la Caja días después).
 *
 *  2. COSTO DE ENVÍO (egreso): NO es automático. El costo de envío de cada
 *     pedido (`_hpos_ardxoz_woo_costo_envio`) suele ajustarse durante el día, por
 *     lo que el egreso a "Caja Chica" se registra MANUALMENTE desde un panel
 *     diario en Registro de Movimientos: el admin revisa los pedidos del día (con
 *     método de envío permitido), corrige montos si hace falta y valida; al
 *     validar se genera un egreso POR PEDIDO con fecha = creación del pedido. Los
 *     métodos de envío que disparan el egreso son CONFIGURABLES (no hardcodeados).
 *
 * Diseño común (trazabilidad por ref + opt-in por configuración):
 *  - Trazabilidad por (ref_table, ref_id=order_id): un `ref_table` distinto por
 *    automatización (`order_deposit` / `order_shipping`). El **envío** admite como
 *    máximo UN egreso por pedido (idempotencia por existencia). El **depósito** usa
 *    **reconciliación incremental** (puede haber varios movimientos por pedido: la
 *    suma neta = `monto_deposito`); cada disparo registra solo el delta pendiente.
 *  - Requiere cuenta + categoría (de la naturaleza correcta) configuradas; si
 *    falta cualquiera, la automatización se omite en silencio (off).
 *  - El egreso de envío usa skip_balance_check: es un hecho consumado (el
 *    pedido ya se entregó); no se bloquea por saldo.
 *  - Fecha del movimiento: el depósito usa la **fecha real del depósito**; el
 *    envío usa la fecha de creación del pedido.
 *  - Lectura de metas vía Meta_Resolver del plugin orders (HPOS + fallback ACF
 *    para pedidos viejos) si está disponible; si no, get_meta directo.
 */
class FIN_Orders
{
    // Depósito → ingreso.
    const OPT_DEP_AUTOPOST = 'fin_order_deposit_autopost';
    const OPT_DEP_ACCOUNT  = 'fin_order_deposit_account';
    const OPT_DEP_CATEGORY = 'fin_order_deposit_category';

    // Costo de envío → egreso (Caja Chica). Registro MANUAL por día (no auto).
    const OPT_SHIP_ACCOUNT  = 'fin_order_shipping_account';
    const OPT_SHIP_CATEGORY = 'fin_order_shipping_category';
    const OPT_SHIP_METHODS  = 'fin_order_shipping_methods'; // array de títulos permitidos

    // Pago de costo de envío IBEX → egreso. Registro MANUAL por MES (un egreso
    // mensual = total del costo de envío de los pedidos IBEX del mes).
    const OPT_IBEX_ACCOUNT  = 'fin_order_ibex_account';
    const OPT_IBEX_CATEGORY = 'fin_order_ibex_category'; // legado: categoría única (fallback de lectura)
    // Mapa sucursal => category_id (egreso). El pago mensual IBEX se divide por
    // sucursal: un egreso por sucursal con su propia categoría. Reemplaza a
    // OPT_IBEX_CATEGORY; esta última se conserva solo como fallback para
    // instalaciones previas que aún no migraron el mapeo.
    const OPT_IBEX_CATEGORIES = 'fin_order_ibex_categories';
    const OPT_IBEX_METHODS  = 'fin_order_ibex_methods'; // array de títulos permitidos
    // Corte 'Y-m' elegido por el admin en Configuración: el panel IBEX muestra ese
    // mes y los posteriores, y OCULTA los anteriores (meses ya cerrados fuera del
    // sistema). No crea ni borra egresos: es solo un filtro de visualización.
    // Vacío = se muestran todos los meses (comportamiento por defecto).
    const OPT_IBEX_HIDE_BEFORE = 'fin_order_ibex_hide_before';

    const META_DEPOSIT  = '_hpos_ardxoz_woo_monto_deposito';
    const META_SHIPPING = '_hpos_ardxoz_woo_costo_envio';

    /** Métodos de envío permitidos por defecto (primera vez, hasta configurar). Por TÍTULO. */
    const DEFAULT_SHIPPING_TITLES = ['SUECIA', 'CBS'];

    /** Método(s) por defecto del pago mensual IBEX. Por TÍTULO. */
    const DEFAULT_IBEX_TITLES = ['IBEX'];

    /** Sucursales por defecto si DEMV no está disponible. En MAYÚSCULAS. */
    const DEFAULT_IBEX_SUCURSALES = ['COCHABAMBA', 'SANTA CRUZ'];

    /** Bucket para pedidos IBEX sin sucursal detectable (o sin DEMV). */
    const IBEX_SUC_NONE = 'SIN SUCURSAL';

    /** Caché (transient) de métodos de envío recientes para el selector de Configuración. */
    const TRANSIENT_RECENT_METHODS = 'fin_recent_shipping_methods';

    const META_DEPOSIT_DATE = '_hpos_ardxoz_woo_fecha_deposito';

    public static function init()
    {
        // Prioridad 20: después de que otros plugins (DEMV, etc.) hayan podido
        // calcular/guardar metas en la transición a completado.
        add_action('woocommerce_order_status_completed', [__CLASS__, 'on_order_completed'], 20, 2);

        // DEMV avisa cada vez que registra un depósito (Express/Admin/Caja). El
        // ingreso se reconoce EN EL MOMENTO DEL DEPÓSITO, no al completar, para que
        // la caja no quede descuadrada mientras el pedido sigue pendiente (típico
        // en pedidos con efectivo que se completan al aprobar la Caja días después).
        add_action('hawd_deposit_registered', [__CLASS__, 'on_deposit_registered'], 10, 2);
    }

    /**
     * Handler del cambio de estado a "completado". Reconcilia por si el depósito
     * se registró por una vía que no disparó `hawd_deposit_registered` (legado,
     * edición manual del meta): ingresa el delta que aún falte.
     *
     * @param int            $order_id
     * @param WC_Order|mixed $order
     */
    public static function on_order_completed($order_id, $order = null)
    {
        if (!($order instanceof WC_Order)) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }

        self::reconcile_deposit_income($order);
        // El egreso de costo de envío YA NO es automático: se registra a mano
        // desde el panel diario (ver shipping_day_orders / register_shipping_egreso).
    }

    /**
     * Handler del aviso de depósito de DEMV (`hawd_deposit_registered`): ingresa
     * a la cuenta el delta del depósito recién registrado.
     *
     * @param int            $order_id
     * @param WC_Order|mixed $order
     */
    public static function on_deposit_registered($order_id, $order = null)
    {
        if (!($order instanceof WC_Order)) {
            $order = wc_get_order($order_id);
        }
        if ($order) {
            self::reconcile_deposit_income($order);
        }
    }

    // ── Automatización 1: depósito → ingreso (reconciliación incremental) ────
    //
    // El ingreso se reconoce EN EL MOMENTO DEL DEPÓSITO (action de DEMV
    // `hawd_deposit_registered`), no al completar. `reconcile_deposit_income()`
    // compara `monto_deposito` actual vs. lo ya ingresado y registra solo el delta.
    // Pedidos mitad efectivo/QR: la parte QR entra al depositar por Express y la
    // parte de efectivo al aprobar la Caja (que la pliega en `monto_deposito`),
    // cada una con su fecha real. No se lee `monto_efectivo` aquí: el plegado lo
    // hace DEMV. Idempotente por reconciliación (no duplica).

    public static function deposit_enabled()
    {
        return (int) get_option(self::OPT_DEP_AUTOPOST, 0) === 1;
    }

    /**
     * Reconciliación incremental del depósito → ingreso. Se invoca cuando DEMV
     * registra/actualiza el depósito (`hawd_deposit_registered`) y, como red de
     * seguridad, al completar el pedido.
     *
     * En vez de un único ingreso por pedido, compara el `monto_deposito` ACTUAL
     * contra lo ya ingresado en el ledger para ese pedido (suma neta) y registra
     * únicamente el DELTA positivo. Así:
     *   - El dinero entra a la cuenta apenas se registra el depósito (no hay que
     *     esperar a "completado"), de modo que la caja cuadra con el banco.
     *   - Pedidos mitad efectivo/QR generan DOS ingresos: la parte QR al depositar
     *     por Express y la parte de efectivo al aprobar la Caja, cada una con su
     *     fecha real de depósito.
     *   - Es idempotente: si no hay delta (ya ingresado todo, o registros antiguos
     *     hechos al completar) no registra nada. Una baja del depósito NO genera
     *     contrasiento automático (corrección manual).
     */
    private static function reconcile_deposit_income(WC_Order $order)
    {
        if (!self::deposit_enabled()) {
            return;
        }
        $order_id = $order->get_id();

        $account_id = self::valid_account(self::OPT_DEP_ACCOUNT);
        if ($account_id <= 0) {
            return; // sin caja configurada
        }
        $category_id = self::valid_category(self::OPT_DEP_CATEGORY, 'ingreso');
        if ($category_id <= 0) {
            return; // sin categoría ingreso configurada
        }

        // LOCK por pedido: serializa la reconciliación de ESTE depósito entre
        // peticiones/conexiones distintas. El bloque leer-delta-registrar es un
        // "check-then-act": sin lock, dos disparos concurrentes (doble clic /
        // reintento simultáneo del mismo depósito) podrían leer AMBOS `already=0`
        // —ninguno commiteó aún— y registrar el total dos veces. GET_LOCK es un lock
        // asesor por NOMBRE: la 2.ª petición espera a que la 1.ª commitee y entonces
        // ve el monto ya ingresado (delta 0). Si no se obtiene el lock, otra petición
        // está reconciliando este mismo pedido ahora mismo: salimos sin duplicar.
        global $wpdb;
        $lock = 'fin_dep_recon_' . $order_id;
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) {
            return;
        }

        try {
            // AUTORITATIVO: el monto del depósito se lee DIRECTO de la BD, no del
            // objeto $order que llega por los hooks. Ese objeto puede tener el meta
            // seteado en memoria por update_meta_data() pero SIN persistir todavía
            // (p.ej. un save() de Express que falló a media escritura durante el bulk,
            // o un status_transition a "completado" disparado sobre un pedido cuyo
            // guardado luego se revirtió). Leer ese valor de memoria registraría un
            // INGRESO FANTASMA: Finanzas tendría el ingreso aunque el pedido no quede
            // con metas de depósito ni completado. Si en BD no hay depósito
            // persistido, no se registra nada.
            $deposited = self::persisted_deposit_amount($order_id);
            if ($deposited <= 0) {
                return; // sin depósito persistido todavía
            }

            $already = FIN_Movements::posted_amount_for_ref(FIN_Movements::REF_ORDER_DEPOSIT, $order_id);
            $delta   = round($deposited - $already, 2);
            if ($delta <= 0.01) {
                return; // ya ingresado (o el depósito no aumentó): nada que registrar
            }

            $number = (string) $order->get_order_number();
            $desc   = $already > 0.01
                ? sprintf('Depósito pedido #%s (complemento)', $number)
                : sprintf('Depósito pedido #%s', $number);

            FIN_Movements::register([
                'account_id'    => $account_id,
                'type'          => 'ingreso',
                'amount'        => $delta,
                'category_id'   => $category_id,
                'description'   => $desc,
                'movement_date' => self::deposit_date($order),
                'ref_table'     => FIN_Movements::REF_ORDER_DEPOSIT,
                'ref_id'        => $order_id,
                'ref_code'      => $number,
                'user_id'       => get_current_user_id(),
            ]);
            // El WP_Error eventual se ignora: el registro financiero no debe romper el
            // flujo del depósito/cambio de estado del pedido.
        } finally {
            // Se libera siempre (incluido el `return` dentro del try). Aun si el
            // script muriera antes, MySQL suelta el lock al cerrar la conexión.
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    // ── Costo de envío → egreso (Caja Chica) — registro MANUAL por día ───────

    /** Métodos de envío permitidos (títulos) configurados; default si no hay. */
    public static function allowed_methods()
    {
        $v = get_option(self::OPT_SHIP_METHODS, null);
        if (!is_array($v)) {
            return self::DEFAULT_SHIPPING_TITLES;
        }
        $v = array_values(array_filter(array_map('trim', $v), 'strlen'));
        return $v ?: self::DEFAULT_SHIPPING_TITLES;
    }

    /** ¿Está el panel diario operativo? Requiere caja + categoría egreso + métodos. */
    public static function shipping_configured()
    {
        return self::valid_account(self::OPT_SHIP_ACCOUNT) > 0
            && self::valid_category(self::OPT_SHIP_CATEGORY, 'egreso') > 0
            && !empty(self::allowed_methods());
    }

    /**
     * Estados de pedido elegibles para el panel: todos los registrados en WC
     * (incluye los custom del plugin status) MENOS cancelado y reembolsado.
     * Devuelve slugs sin el prefijo 'wc-'.
     */
    public static function eligible_statuses()
    {
        $all     = array_keys(wc_get_order_statuses()); // ['wc-pending', ...]
        $exclude = ['wc-cancelled', 'wc-refunded'];
        $out     = [];
        foreach ($all as $s) {
            if (in_array($s, $exclude, true)) {
                continue;
            }
            $out[] = preg_replace('/^wc-/', '', $s);
        }
        return $out;
    }

    /**
     * Títulos de método de envío usados en los últimos $months meses (para el
     * selector de Configuración). Cacheado en transient; se invalida al guardar.
     *
     * @return string[] títulos únicos, ordenados.
     */
    public static function recent_shipping_methods($months = 6)
    {
        $cached = get_transient(self::TRANSIENT_RECENT_METHODS);
        if (is_array($cached)) {
            return $cached;
        }

        // Solo IDs de pedidos recientes (mucho más liviano que cargar objetos).
        // Cap de 5000: suficiente para descubrir todos los métodos en uso.
        $ids = wc_get_orders([
            'limit'        => 5000,
            'type'         => 'shop_order',
            'status'       => self::eligible_statuses(),
            'date_created' => '>=' . (time() - $months * 30 * DAY_IN_SECONDS),
            'orderby'      => 'date',
            'order'        => 'DESC',
            'return'       => 'ids',
        ]);

        $titles = [];
        if (!empty($ids)) {
            // Una sola query a la tabla de ítems (compartida HPOS/legacy): títulos
            // distintos de los métodos de envío de esos pedidos.
            global $wpdb;
            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $names = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT order_item_name
                 FROM {$wpdb->prefix}woocommerce_order_items
                 WHERE order_item_type = 'shipping' AND order_id IN ($placeholders)",
                ...$ids
            ));
            foreach ((array) $names as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    $titles[$name] = true;
                }
            }
        }
        $titles = array_keys($titles);
        sort($titles, SORT_NATURAL | SORT_FLAG_CASE);

        set_transient(self::TRANSIENT_RECENT_METHODS, $titles, 12 * HOUR_IN_SECONDS);
        return $titles;
    }

    /** Invalida la caché de métodos recientes (al guardar configuración de envío). */
    public static function flush_recent_methods_cache()
    {
        delete_transient(self::TRANSIENT_RECENT_METHODS);
    }

    /**
     * Pedidos elegibles (método de envío permitido, estado ≠ cancelado/reembolsado)
     * creados en el rango [$from, $to], agrupados por día de creación.
     *
     * @param string $from 'Y-m-d'
     * @param string $to   'Y-m-d'
     * @return array  ['Y-m-d' => ['orders'=>[...], 'pending_total'=>float,
     *                 'pending_count'=>int, 'validated_count'=>int]] ordenado desc.
     */
    public static function shipping_day_orders($from, $to)
    {
        $methods = self::allowed_methods();
        if (empty($methods)) {
            return [];
        }

        list($from_ts, $to_ts) = fin_order_range_ts($from, $to);
        if (!$from_ts || !$to_ts || $from_ts > $to_ts) {
            return [];
        }

        $orders = wc_get_orders([
            'limit'        => -1,
            'type'         => 'shop_order',
            'status'       => self::eligible_statuses(),
            'date_created' => $from_ts . '...' . $to_ts,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'return'       => 'objects',
        ]);

        // 1.ª pasada: filtrar candidatos (método permitido + fecha de creación).
        $candidates = [];
        foreach ($orders as $order) {
            if (!($order instanceof WC_Order)) {
                continue;
            }
            $title = self::matched_shipping_title($order);
            if ($title === '') {
                continue; // método no permitido
            }
            $created = $order->get_date_created();
            if (!$created) {
                continue;
            }
            $candidates[] = ['order' => $order, 'title' => $title, 'day' => $created->date('Y-m-d')];
        }

        // Una sola query para saber qué pedidos ya tienen egreso de envío (evita N+1).
        $ids = array_map(function ($c) { return $c['order']->get_id(); }, $candidates);
        $validated_set = array_flip(FIN_Movements::existing_refs(FIN_Movements::REF_ORDER_SHIPPING, $ids));

        // 2.ª pasada: construir la estructura por día.
        $days = [];
        foreach ($candidates as $c) {
            $order     = $c['order'];
            $day       = $c['day'];
            $order_id  = $order->get_id();
            $validated = isset($validated_set[$order_id]);
            $amount    = self::read_amount($order, self::META_SHIPPING);

            if (!isset($days[$day])) {
                $days[$day] = ['orders' => [], 'pending_total' => 0.0, 'pending_count' => 0, 'validated_count' => 0];
            }
            $days[$day]['orders'][] = [
                'id'        => $order_id,
                'number'    => (string) $order->get_order_number(),
                'method'    => $c['title'],
                'amount'    => $amount,
                'validated' => $validated,
                'status'    => wc_get_order_status_name($order->get_status()),
                'edit_url'  => $order->get_edit_order_url(),
            ];
            if ($validated) {
                $days[$day]['validated_count']++;
            } else {
                $days[$day]['pending_count']++;
                $days[$day]['pending_total'] += $amount;
            }
        }

        // Solo se listan los días con pedidos pendientes: al validar todo un día,
        // su recuadro desaparece del panel (los días ya saldados no se muestran).
        $days = array_filter($days, static function ($d) {
            return $d['pending_count'] > 0;
        });

        krsort($days); // días más recientes primero
        return $days;
    }

    /**
     * Escribe el costo de envío en el pedido (meta HPOS). Usado por el panel al
     * "Guardar montos". No toca pedidos cuyo egreso ya fue validado.
     *
     * @return bool true si guardó.
     */
    public static function set_shipping_cost($order_id, $amount)
    {
        $order = wc_get_order((int) $order_id);
        if (!$order) {
            return false;
        }
        if (FIN_Movements::exists_for_ref(FIN_Movements::REF_ORDER_SHIPPING, (int) $order_id)) {
            return false; // ya validado: bloqueado
        }
        $amount = round(max(0, (float) $amount), 2);
        $order->update_meta_data(self::META_SHIPPING, $amount);
        $order->save();
        return true;
    }

    /**
     * Registra el egreso de costo de envío de UN pedido (idempotente). Fecha del
     * movimiento = fecha de CREACIÓN del pedido. Si $amount es null, lo lee del meta.
     *
     * @return int|WP_Error|0  id del movimiento, 0 si se omite (ya existe / sin monto).
     */
    public static function register_shipping_egreso($order_id, $amount = null)
    {
        $order = wc_get_order((int) $order_id);
        if (!$order) {
            return new WP_Error('fin_order_not_found', 'Pedido no encontrado.');
        }
        $order_id = $order->get_id();

        if (FIN_Movements::exists_for_ref(FIN_Movements::REF_ORDER_SHIPPING, $order_id)) {
            return 0; // ya registrado
        }

        $matched = self::matched_shipping_title($order);
        if ($matched === '') {
            return new WP_Error('fin_ship_method', 'El método de envío del pedido no está permitido.');
        }

        $account_id = self::valid_account(self::OPT_SHIP_ACCOUNT);
        if ($account_id <= 0) {
            return new WP_Error('fin_ship_account', 'No hay una cuenta válida configurada para el egreso de envío.');
        }
        $category_id = self::valid_category(self::OPT_SHIP_CATEGORY, 'egreso');
        if ($category_id <= 0) {
            return new WP_Error('fin_ship_category', 'No hay una categoría egreso válida configurada para el envío.');
        }

        $amount = ($amount === null)
            ? self::read_amount($order, self::META_SHIPPING)
            : round(max(0, (float) $amount), 2);
        if ($amount <= 0) {
            return 0; // sin costo de envío: nada que registrar
        }

        $number = (string) $order->get_order_number();
        return FIN_Movements::register([
            'account_id'         => $account_id,
            'type'               => 'egreso',
            'amount'             => $amount,
            'category_id'        => $category_id,
            'description'        => sprintf('Costo de envío %s pedido #%s', $matched, $number),
            'movement_date'      => self::created_date($order),
            'ref_table'          => FIN_Movements::REF_ORDER_SHIPPING,
            'ref_id'             => $order_id,
            'ref_code'           => $number,
            'skip_balance_check' => true, // hecho consumado: no bloquear por saldo
            'user_id'            => get_current_user_id(),
        ]);
    }

    // ── Pago de costo de envío IBEX → egreso (un egreso por MES) ─────────────

    /** Métodos de envío IBEX configurados (títulos); default ['IBEX'] si no hay. */
    public static function ibex_methods()
    {
        $v = get_option(self::OPT_IBEX_METHODS, null);
        if (!is_array($v)) {
            return self::DEFAULT_IBEX_TITLES;
        }
        $v = array_values(array_filter(array_map('trim', $v), 'strlen'));
        return $v ?: self::DEFAULT_IBEX_TITLES;
    }

    /**
     * Sucursales del pago IBEX (en MAYÚSCULAS), tomadas de DEMV si está disponible.
     * El pago mensual se divide en un egreso por cada una de estas sucursales.
     *
     * @return string[]
     */
    public static function ibex_sucursales()
    {
        if (defined('HPOS_Ardxoz_Woo_DEMV_Config::SUCURSALES')) {
            $list = HPOS_Ardxoz_Woo_DEMV_Config::SUCURSALES;
            if (is_array($list)) {
                $list = array_values(array_filter(array_map(static function ($s) {
                    return strtoupper(trim((string) $s));
                }, $list), 'strlen'));
                if (!empty($list)) {
                    return $list;
                }
            }
        }
        return self::DEFAULT_IBEX_SUCURSALES;
    }

    /**
     * Mapa sucursal => category_id (egreso) configurado para el pago IBEX. Solo
     * incluye sucursales con una categoría egreso ACTIVA y válida.
     *
     * @return array<string,int>
     */
    public static function ibex_categories()
    {
        $raw = get_option(self::OPT_IBEX_CATEGORIES, null);
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $suc => $cid) {
                $suc = strtoupper(trim((string) $suc));
                $cid = (int) $cid;
                if ($suc === '' || $cid <= 0) {
                    continue;
                }
                $cat = FIN_Categories::get($cid);
                if ($cat && (int) $cat['active'] === 1 && $cat['nature'] === 'egreso') {
                    $out[$suc] = $cid;
                }
            }
        }
        return $out;
    }

    /**
     * Categoría egreso (id) para una sucursal del pago IBEX, o 0 si no hay.
     * Cae al esquema legado de categoría única (OPT_IBEX_CATEGORY) cuando no hay
     * ningún mapeo por sucursal configurado todavía.
     */
    public static function ibex_category_for($sucursal)
    {
        $map = self::ibex_categories();
        $suc = strtoupper(trim((string) $sucursal));
        if (isset($map[$suc])) {
            return (int) $map[$suc];
        }
        // Fallback legado: si no hay NINGÚN mapeo por sucursal, usar la categoría
        // única anterior (instalaciones que aún no migraron).
        if (empty($map)) {
            return self::valid_category(self::OPT_IBEX_CATEGORY, 'egreso');
        }
        return 0;
    }

    /**
     * Índice estable (1-based) de una sucursal dentro de ibex_sucursales(), usado
     * para componer el ref_id del egreso (mes·sucursal). Fallback = 99.
     */
    public static function ibex_sucursal_index($sucursal)
    {
        $suc  = strtoupper(trim((string) $sucursal));
        $list = self::ibex_sucursales();
        $pos  = array_search($suc, $list, true);
        if ($pos !== false) {
            return (int) $pos + 1;
        }
        return 99; // bucket "sin sucursal" / desconocida
    }

    /** ¿Está el panel mensual IBEX operativo? Requiere caja + ≥1 categoría egreso + métodos. */
    public static function ibex_configured()
    {
        return self::valid_account(self::OPT_IBEX_ACCOUNT) > 0
            && (!empty(self::ibex_categories()) || self::valid_category(self::OPT_IBEX_CATEGORY, 'egreso') > 0)
            && !empty(self::ibex_methods());
    }

    /** Clave numérica de mes (AAAAMM) usada como ref_id del egreso mensual. */
    private static function ibex_month_key($year, $month)
    {
        return (int) $year * 100 + (int) $month;
    }

    /**
     * Pedidos IBEX (método permitido, estado elegible) creados en el rango,
     * con su costo de envío. Base común del panel y del registro mensual.
     *
     * @return array Lista de ['order'=>WC_Order,'title'=>string,'month'=>'Y-m',
     *               'amount'=>float,'sucursal'=>string]
     */
    private static function ibex_candidates($from, $to)
    {
        $methods = self::ibex_methods();
        if (empty($methods)) {
            return [];
        }
        list($from_ts, $to_ts) = fin_order_range_ts($from, $to);
        if (!$from_ts || !$to_ts || $from_ts > $to_ts) {
            return [];
        }

        $orders = wc_get_orders([
            'limit'        => -1,
            'type'         => 'shop_order',
            'status'       => self::eligible_statuses(),
            'date_created' => $from_ts . '...' . $to_ts,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'return'       => 'objects',
        ]);

        $out = [];
        foreach ($orders as $order) {
            if (!($order instanceof WC_Order)) {
                continue;
            }
            $title = self::matched_title_in($order, $methods);
            if ($title === '') {
                continue;
            }
            $created = $order->get_date_created();
            if (!$created) {
                continue;
            }
            $out[] = [
                'order'  => $order,
                'title'  => $title,
                'month'  => $created->date('Y-m'),
                'amount' => self::read_amount($order, self::META_SHIPPING),
            ];
        }

        // Resolver la sucursal de cada pedido en UNA sola tanda (DEMV). En la
        // práctica un pedido sale de una sola sucursal (no hay mixtos): tomamos la
        // primera detectada; si no hay ninguna (o DEMV no está), va al bucket
        // "SIN SUCURSAL" para que el total del mes siga cuadrando.
        $ids     = array_map(static function ($c) { return $c['order']->get_id(); }, $out);
        $suc_map = self::order_sucursal_map($ids);
        foreach ($out as &$c) {
            $c['sucursal'] = $suc_map[$c['order']->get_id()] ?? self::IBEX_SUC_NONE;
        }
        unset($c);

        return $out;
    }

    /**
     * Resuelve, en bloque, la sucursal "principal" de cada pedido. Reutiliza la
     * lógica de DEMV (atributo `pa_sucursal` de los productos) para coincidir con
     * lo que ve el administrador en la Caja DEMV. Si DEMV no está disponible,
     * todos caen al bucket IBEX_SUC_NONE.
     *
     * @param int[] $ids
     * @return array<int,string> order_id => sucursal (MAYÚSCULAS o IBEX_SUC_NONE)
     */
    private static function order_sucursal_map(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        if (empty($ids)) {
            return $out;
        }

        if (!class_exists('HPOS_Ardxoz_Woo_DEMV_Query')
            || !method_exists('HPOS_Ardxoz_Woo_DEMV_Query', 'get_orders_taxonomies')) {
            return $out; // sin DEMV: todos → IBEX_SUC_NONE (lo aplica el caller)
        }

        $known = self::ibex_sucursales();
        $tax   = HPOS_Ardxoz_Woo_DEMV_Query::get_orders_taxonomies($ids);
        foreach ($ids as $oid) {
            $sucs = isset($tax[$oid]['sucursales']) ? (array) $tax[$oid]['sucursales'] : [];
            $sucs = array_values(array_filter(array_map(static function ($s) {
                return strtoupper(trim((string) $s));
            }, $sucs), 'strlen'));
            if (empty($sucs)) {
                continue; // caller usa IBEX_SUC_NONE
            }
            // Preferir una sucursal conocida (config); si ninguna lo es, la primera.
            $primary = $sucs[0];
            foreach ($sucs as $s) {
                if (in_array($s, $known, true)) {
                    $primary = $s;
                    break;
                }
            }
            $out[$oid] = $primary;
        }
        return $out;
    }

    /**
     * Pedidos IBEX agrupados por MES (Y-m) y, dentro, por SUCURSAL — con total,
     * cantidad y estado de validación de cada sucursal (un egreso por sucursal).
     * Para el panel de la pestaña de envíos.
     *
     * @return array ['Y-m' => [
     *     'total'=>float, 'count'=>int,
     *     'legacy'=>bool, 'legacy_amount'=>float|null, 'legacy_date'=>string|null,
     *     'sucursales'=>['SUC'=>['total'=>float,'count'=>int,'category_id'=>int,
     *                    'validated'=>bool,'movement_amount'=>float|null,
     *                    'movement_date'=>string|null]],
     * ]] orden desc por mes.
     */
    public static function ibex_month_orders($from, $to)
    {
        $candidates = self::ibex_candidates($from, $to);

        $months = [];
        foreach ($candidates as $c) {
            $m   = $c['month'];
            $suc = $c['sucursal'];
            if (!isset($months[$m])) {
                $months[$m] = [
                    'total'         => 0.0,
                    'count'         => 0,
                    'legacy'        => false,
                    'legacy_amount' => null,
                    'legacy_date'   => null,
                    'sucursales'    => [],
                ];
            }
            if (!isset($months[$m]['sucursales'][$suc])) {
                $months[$m]['sucursales'][$suc] = [
                    'total'           => 0.0,
                    'count'           => 0,
                    'category_id'     => self::ibex_category_for($suc),
                    'validated'       => false,
                    'movement_amount' => null,
                    'movement_date'   => null,
                ];
            }
            $months[$m]['total'] += $c['amount'];
            $months[$m]['count']++;
            $months[$m]['sucursales'][$suc]['total'] += $c['amount'];
            $months[$m]['sucursales'][$suc]['count']++;
        }

        // Estado de validación: legado (un solo egreso del mes, esquema anterior)
        // y por sucursal (egreso mes·sucursal).
        foreach ($months as $m => &$info) {
            list($y, $mo) = array_map('intval', explode('-', $m));
            $month_key = self::ibex_month_key($y, $mo);

            $legacy = FIN_Movements::get_by_ref(FIN_Movements::REF_ORDER_IBEX, $month_key);
            if ($legacy) {
                $info['legacy']        = true;
                $info['legacy_amount'] = (float) $legacy['amount'];
                $info['legacy_date']   = (string) $legacy['movement_date'];
            }

            foreach ($info['sucursales'] as $suc => &$s) {
                $ref_id = self::ibex_suc_ref_id($month_key, $suc);
                $mv     = FIN_Movements::get_by_ref(FIN_Movements::REF_ORDER_IBEX, $ref_id);
                if ($mv) {
                    $s['validated']       = true;
                    $s['movement_amount'] = (float) $mv['amount'];
                    $s['movement_date']   = (string) $mv['movement_date'];
                }
            }
            unset($s);
            ksort($info['sucursales']);
        }
        unset($info);

        // Corte de visualización elegido en Configuración: ocultar los meses
        // anteriores al corte (claves 'Y-m' comparadas lexicográficamente).
        // Vacío = mostrar todos.
        $cutoff = self::ibex_hide_before();
        if ($cutoff !== '') {
            $months = array_filter($months, static function ($m) use ($cutoff) {
                return strcmp($m, $cutoff) >= 0;
            }, ARRAY_FILTER_USE_KEY);
        }

        krsort($months); // meses más recientes primero
        return $months;
    }

    /** ref_id del egreso de un mes·sucursal: AAAAMM·índice (2 dígitos). */
    private static function ibex_suc_ref_id($month_key, $sucursal)
    {
        return (int) $month_key * 100 + self::ibex_sucursal_index($sucursal);
    }

    /**
     * Corte 'Y-m' configurado para el panel IBEX (primer mes visible). Cadena
     * vacía si no hay corte (se muestran todos los meses). Valida el formato.
     */
    public static function ibex_hide_before()
    {
        $v = (string) get_option(self::OPT_IBEX_HIDE_BEFORE, '');
        return preg_match('/^\d{4}-\d{2}$/', $v) ? $v : '';
    }

    /**
     * Meses 'Y-m' (ascendente) ofrecidos en el selector de corte de Configuración:
     * los últimos 36 meses hasta el mes actual.
     *
     * IMPORTANTE: se generan por rango de fechas SIN consultar pedidos. Escanear
     * el histórico de pedidos (cargando objetos) agotaba la memoria de PHP en
     * tiendas grandes y producía un "error crítico" al abrir Configuración.
     *
     * @return string[]
     */
    public static function ibex_available_months()
    {
        $base   = strtotime(wp_date('Y-m-01') . ' 12:00:00');
        $months = [];
        for ($i = 0; $i < 36; $i++) {
            $months[] = wp_date('Y-m', strtotime("-$i month", $base));
        }
        sort($months); // ascendente: del más antiguo al más reciente
        return $months;
    }

    /**
     * Registra el egreso de IBEX de UN mes·sucursal: UN movimiento = suma del
     * costo de envío de los pedidos IBEX de esa sucursal en el mes. Idempotente
     * por mes·sucursal (ref_id = AAAAMM·índice). Fecha = último día del mes.
     *
     * @param string $month    'Y-m'
     * @param string $sucursal Nombre de sucursal (MAYÚSCULAS) o IBEX_SUC_NONE.
     * @return int|WP_Error|0 id del movimiento; 0 si ya existe o total 0.
     */
    public static function register_ibex_sucursal($month, $sucursal)
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', (string) $month, $mm)) {
            return new WP_Error('fin_ibex_month', 'Mes inválido.');
        }
        $sucursal = strtoupper(trim((string) $sucursal));
        if ($sucursal === '') {
            return new WP_Error('fin_ibex_suc', 'Sucursal inválida.');
        }

        $year      = (int) $mm[1];
        $mon       = (int) $mm[2];
        $month_key = self::ibex_month_key($year, $mon);

        // Si el mes ya se registró con el esquema antiguo (un solo egreso del
        // mes), no se permite además el desglose por sucursal: el mes ya está pago.
        if (FIN_Movements::exists_for_ref(FIN_Movements::REF_ORDER_IBEX, $month_key)) {
            return 0;
        }

        $ref_id = self::ibex_suc_ref_id($month_key, $sucursal);
        if (FIN_Movements::exists_for_ref(FIN_Movements::REF_ORDER_IBEX, $ref_id)) {
            return 0; // sucursal de ese mes ya registrada
        }

        $account_id = self::valid_account(self::OPT_IBEX_ACCOUNT);
        if ($account_id <= 0) {
            return new WP_Error('fin_ibex_account', 'No hay una cuenta válida configurada para el pago IBEX.');
        }
        $category_id = self::ibex_category_for($sucursal);
        if ($category_id <= 0) {
            return new WP_Error('fin_ibex_category', sprintf('No hay categoría egreso configurada para la sucursal %s.', $sucursal));
        }

        $from = sprintf('%04d-%02d-01', $year, $mon);
        $to   = date('Y-m-t', strtotime($from));

        $candidates = self::ibex_candidates($from, $to);
        $total = 0.0; $count = 0;
        foreach ($candidates as $c) {
            if ($c['sucursal'] !== $sucursal) {
                continue;
            }
            $total += $c['amount'];
            $count++;
        }
        $total = round($total, 2);
        if ($total <= 0) {
            return 0; // nada que registrar para esta sucursal
        }

        return FIN_Movements::register([
            'account_id'         => $account_id,
            'type'               => 'egreso',
            'amount'             => $total,
            'category_id'        => $category_id,
            'description'        => sprintf('Costo de envío IBEX %s — %s (%d pedido%s)', $month, $sucursal, $count, $count === 1 ? '' : 's'),
            'movement_date'      => $to . ' 23:59:59',
            'ref_table'          => FIN_Movements::REF_ORDER_IBEX,
            'ref_id'             => $ref_id,
            'ref_code'           => 'IBEX ' . $month . ' ' . $sucursal,
            'skip_balance_check' => true, // hecho consumado: no bloquear por saldo
            'user_id'            => get_current_user_id(),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Cuenta activa configurada en $opt, o 0. */
    private static function valid_account($opt)
    {
        $id = (int) get_option($opt, 0);
        if ($id <= 0) {
            return 0;
        }
        $acc = FIN_Accounts::get($id);
        return ($acc && (int) $acc['active'] === 1) ? (int) $acc['id'] : 0;
    }

    /** Categoría activa configurada en $opt cuya naturaleza coincida, o 0. */
    private static function valid_category($opt, $nature)
    {
        $id = (int) get_option($opt, 0);
        if ($id <= 0) {
            return 0;
        }
        $cat = FIN_Categories::get($id);
        if ($cat && (int) $cat['active'] === 1 && $cat['nature'] === $nature) {
            return (int) $cat['id'];
        }
        return 0;
    }

    /**
     * Devuelve el título del método de envío del pedido si está entre los
     * permitidos (configurables); cadena vacía en caso contrario. Compara por
     * TÍTULO (get_method_title), coherente con DEMV/actions.
     */
    private static function matched_shipping_title(WC_Order $order)
    {
        return self::matched_title_in($order, self::allowed_methods());
    }

    /** Título del método de envío del pedido si está en $allowed; '' si no. */
    private static function matched_title_in(WC_Order $order, array $allowed)
    {
        foreach ($order->get_shipping_methods() as $method) {
            $title = trim((string) $method->get_method_title());
            if (in_array($title, $allowed, true)) {
                return $title;
            }
        }
        return '';
    }

    /** Lee un meta numérico del pedido (con fallback legacy) y lo normaliza a float >= 0. */
    private static function read_amount(WC_Order $order, $hpos_key)
    {
        $raw = '';
        if (class_exists('\HPOS\Ardxoz\Woo\Orders\Meta_Resolver')) {
            $raw = \HPOS\Ardxoz\Woo\Orders\Meta_Resolver::get($order, $hpos_key);
        } else {
            $raw = $order->get_meta($hpos_key, true);
        }
        // Normaliza separador de miles por las dudas (deja el punto decimal).
        $clean = str_replace(',', '', (string) $raw);
        return round(max(0, (float) $clean), 2);
    }

    /** Fecha de completado del pedido en 'Y-m-d H:i:s', o ahora si no está. */
    private static function completed_date(WC_Order $order)
    {
        $dc = $order->get_date_completed();
        if ($dc) {
            return $dc->date('Y-m-d H:i:s');
        }
        return current_time('mysql');
    }

    /**
     * Fecha REAL del depósito (`_hpos_ardxoz_woo_fecha_deposito`, 'Y-m-d') para
     * datar el ingreso en la cuenta. Así la caja cuadra con el extracto bancario
     * por fecha de depósito, no por fecha de completado. Fallback: completado/ahora.
     *
     * Se lee DIRECTO de la BD (autoritativo) por la misma razón que el monto: el
     * objeto en memoria puede traer una fecha sin persistir.
     */
    private static function deposit_date(WC_Order $order)
    {
        $raw = trim((string) self::persisted_meta($order->get_id(), self::META_DEPOSIT_DATE));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw . ' ' . current_time('H:i:s');
        }
        return self::completed_date($order);
    }

    /**
     * Lee un meta del pedido DIRECTO de la BD (autoritativo, inmune al objeto en
     * memoria sin guardar). El sitio es HPOS, así que las metas del pedido viven
     * en `wc_orders_meta` con la clave actual `_hpos_ardxoz_woo_*` (la que escriben
     * MetaOrder y el Express de DEMV); se lee esa tabla por esa clave. Solo si HPOS
     * no estuviera activo en algún entorno se cae a `postmeta`. Devuelve '' si no
     * hay valor persistido.
     */
    private static function persisted_meta($order_id, $key)
    {
        global $wpdb;
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return '';
        }

        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $table = $wpdb->prefix . 'wc_orders_meta';
            $val = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $table WHERE order_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1",
                $order_id, $key
            ));
            return $val !== null ? (string) $val : '';
        }

        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
            $order_id, $key
        ));
        return $val !== null ? (string) $val : '';
    }

    /** Monto del depósito PERSISTIDO en BD, normalizado a float >= 0. */
    private static function persisted_deposit_amount($order_id)
    {
        $clean = str_replace(',', '', (string) self::persisted_meta($order_id, self::META_DEPOSIT));
        return round(max(0, (float) $clean), 2);
    }

    /** Fecha de CREACIÓN del pedido en 'Y-m-d H:i:s', o ahora si no está. */
    private static function created_date(WC_Order $order)
    {
        $dc = $order->get_date_created();
        if ($dc) {
            return $dc->date('Y-m-d H:i:s');
        }
        return current_time('mysql');
    }
}
