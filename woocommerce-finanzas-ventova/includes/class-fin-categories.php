<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CRUD de categorías contables (v1.0).
 *
 *  - `nature` ∈ {ingreso, egreso}. Determina en qué lado del Estado de
 *    Resultados suma la categoría y restringe qué movimientos pueden usarla:
 *    un movimiento `ingreso` solo acepta categorías de nature='ingreso', y un
 *    `egreso` solo nature='egreso' (validado en FIN_Movements).
 *  - `active=0` es soft-disable: la categoría sale de los selects de nuevos
 *    movimientos pero los movimientos históricos la siguen referenciando.
 */
class FIN_Categories
{
    const NATURES = ['ingreso', 'egreso'];

    public static function query(array $args = [])
    {
        global $wpdb;
        $t = FIN_Schema::table('categories');

        $where  = ['1=1'];
        $params = [];
        if (isset($args['active']) && $args['active'] !== '') {
            $where[]  = 'active = %d';
            $params[] = (int) $args['active'];
        }
        if (!empty($args['nature'])) {
            $where[]  = 'nature = %s';
            $params[] = (string) $args['nature'];
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where[]  = 'name LIKE %s';
            $params[] = $like;
        }

        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 500;
        $sql = "SELECT * FROM $t WHERE " . implode(' AND ', $where)
             . " ORDER BY active DESC, nature ASC, name ASC LIMIT $limit";
        $sql = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public static function get($id)
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        global $wpdb;
        $t = FIN_Schema::table('categories');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id), ARRAY_A) ?: null;
    }

    /** Solo activas, opcionalmente filtradas por naturaleza. */
    public static function active_list($nature = '')
    {
        $args = ['active' => 1, 'limit' => 1000];
        if ($nature !== '') {
            $args['nature'] = $nature;
        }
        return self::query($args);
    }

    /**
     * Crea o actualiza una categoría (Motivo).
     *
     *   $args: id, name (requerido), nature ('ingreso'|'egreso'),
     *          group_key (grupo contable de FIN_Groups, requerido),
     *          requires_description (0|1), active (0|1)
     *
     * La naturaleza se deriva del grupo cuando éste es de naturaleza única
     * (todos salvo "Patrimonial"), garantizando coherencia automática.
     *
     * @return int|WP_Error id de la categoría.
     */
    public static function save(array $args)
    {
        $args = array_merge([
            'id'                   => 0,
            'name'                 => '',
            'nature'               => 'egreso',
            'group_key'            => '',
            'requires_description' => 0,
            'active'               => 1,
        ], $args);

        $name = trim((string) $args['name']);
        if ($name === '') {
            return new WP_Error('fin_category_name_required', 'El nombre de la categoría es obligatorio.');
        }
        if (mb_strlen($name) > 150) {
            return new WP_Error('fin_category_name_too_long', 'El nombre supera 150 caracteres.');
        }

        // Grupo contable: obligatorio, existente y no-auto (CMV no admite motivos).
        $group_key = (string) $args['group_key'];
        $group     = FIN_Groups::get($group_key);
        if (!$group) {
            return new WP_Error('fin_category_group_required', 'Debe seleccionar un grupo contable.');
        }
        if (!empty($group['auto'])) {
            return new WP_Error('fin_category_group_auto',
                sprintf('El grupo "%s" se alimenta automáticamente y no admite motivos manuales.', $group['label']));
        }

        // Naturaleza: la del grupo si es única; si el grupo es "ambos", la elegida.
        if ($group['nature'] !== 'ambos') {
            $nature = $group['nature'];
        } else {
            $nature = in_array($args['nature'], self::NATURES, true) ? $args['nature'] : 'egreso';
        }

        $data = [
            'name'                 => sanitize_text_field($name),
            'nature'               => $nature,
            'group_key'            => $group_key,
            'requires_description' => !empty($args['requires_description']) ? 1 : 0,
            'active'               => !empty($args['active']) ? 1 : 0,
            'updated_at'           => current_time('mysql'),
        ];
        $fmt = ['%s', '%s', '%s', '%d', '%d', '%s'];

        global $wpdb;
        $t = FIN_Schema::table('categories');

        $id = (int) $args['id'];
        if ($id > 0) {
            $exists = self::get($id);
            if (!$exists) {
                return new WP_Error('fin_category_not_found', 'Categoría no encontrada.');
            }
            $ok = $wpdb->update($t, $data, ['id' => $id], $fmt, ['%d']);
            if ($ok === false) {
                return new WP_Error('fin_db_update', 'No se pudo actualizar la categoría.');
            }
            return $id;
        }

        $data['created_at'] = current_time('mysql');
        $fmt[] = '%s';
        $ok = $wpdb->insert($t, $data, $fmt);
        if (!$ok) {
            return new WP_Error('fin_db_insert', 'No se pudo crear la categoría.');
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Categoría de sistema para los costos de importación (integración con el
     * plugin de Inventario). Vive en el grupo `compra_inventario` (auto, no
     * editable en el CRUD). Se crea una sola vez y se reutiliza. Salta el guard
     * de "grupo auto" de save() porque es interna, no un motivo manual.
     *
     * @return int id de la categoría (0 si no se pudo crear).
     */
    public static function system_import_costs_id()
    {
        global $wpdb;
        $t = FIN_Schema::table('categories');

        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE group_key = %s AND nature = 'egreso' ORDER BY id ASC LIMIT 1",
            FIN_Groups::COMPRA_INVENTARIO
        ));
        if ($id > 0) {
            return $id;
        }

        $ok = $wpdb->insert($t, [
            'name'                 => 'Costo de importación',
            'nature'               => 'egreso',
            'group_key'            => FIN_Groups::COMPRA_INVENTARIO,
            'requires_description' => 0,
            'active'               => 1,
            'created_at'           => current_time('mysql'),
            'updated_at'           => current_time('mysql'),
        ], ['%s', '%s', '%s', '%d', '%d', '%s', '%s']);

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function toggle_active($id)
    {
        $id  = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('fin_category_not_found', 'Categoría no encontrada.');
        }
        global $wpdb;
        $t   = FIN_Schema::table('categories');
        $new = ((int) $row['active'] === 1) ? 0 : 1;
        $ok  = $wpdb->update($t,
            ['active' => $new, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );
        if ($ok === false) {
            return new WP_Error('fin_db_update', 'No se pudo cambiar el estado de la categoría.');
        }
        return $new;
    }

    public static function nature_label($nature)
    {
        return $nature === 'ingreso' ? 'Ingreso' : 'Egreso';
    }
}
