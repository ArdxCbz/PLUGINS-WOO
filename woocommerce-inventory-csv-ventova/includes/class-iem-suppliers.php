<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CRUD de proveedores (v3.14+; rediseño 3.17).
 *
 *  - Desde 3.17 los campos son: name (persona), company (razón social), phone,
 *    email, address, notes, active. Se retiraron NIT y contact_name.
 *  - `name` es el único campo obligatorio. El resto son textuales opcionales.
 *  - `active=0` es soft-disable: el proveedor desaparece del selector de nuevas
 *    compras pero las compras históricas siguen referenciándolo. No se borra.
 *  - No hay hard-delete porque purchases.supplier_id es una FK lógica sin
 *    ON DELETE: borrar un proveedor dejaría compras huérfanas. Si en algún
 *    momento se necesita, primero hay que reasignar/eliminar sus compras.
 */
class IEM_Suppliers
{
    /** Lista proveedores. */
    public static function query(array $args = [])
    {
        global $wpdb;
        $t = IEM_Schema::table('suppliers');

        $where  = ['1=1'];
        $params = [];
        if (isset($args['active']) && $args['active'] !== '') {
            $where[]  = 'active = %d';
            $params[] = (int) $args['active'];
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where[]  = '(name LIKE %s OR company LIKE %s OR phone LIKE %s OR email LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 500;
        $sql = "SELECT * FROM $t WHERE " . implode(' AND ', $where)
             . " ORDER BY active DESC, name ASC LIMIT $limit";
        $sql = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public static function get($id)
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        global $wpdb;
        $t = IEM_Schema::table('suppliers');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id), ARRAY_A) ?: null;
    }

    /** Lista solo activos — útil para selects de nuevas compras. */
    public static function active_list()
    {
        return self::query(['active' => 1, 'limit' => 1000]);
    }

    /**
     * Crea o actualiza un proveedor.
     *
     *   $args:
     *     id       int    (>0 = update; 0 = insert)
     *     name     string (requerido — persona de contacto)
     *     company  string (razón social)
     *     phone    string
     *     email    string
     *     address  string
     *     notes    string
     *     active   0|1
     *
     * @return int|WP_Error id del proveedor (existente o nuevo).
     */
    public static function save(array $args)
    {
        $args = array_merge([
            'id'      => 0,
            'name'    => '',
            'company' => '',
            'phone'   => '',
            'email'   => '',
            'address' => '',
            'notes'   => '',
            'active'  => 1,
        ], $args);

        $name = trim((string) $args['name']);
        if ($name === '') {
            return new WP_Error('iem_supplier_name_required', 'El nombre del proveedor es obligatorio.');
        }
        if (mb_strlen($name) > 150) {
            return new WP_Error('iem_supplier_name_too_long', 'El nombre supera 150 caracteres.');
        }

        $company = trim((string) $args['company']);
        if (mb_strlen($company) > 150) {
            return new WP_Error('iem_supplier_company_too_long', 'La empresa supera 150 caracteres.');
        }

        $email = trim((string) $args['email']);
        if ($email !== '' && !is_email($email)) {
            return new WP_Error('iem_supplier_email_invalid', 'El email del proveedor no es válido.');
        }

        $data = [
            'name'       => $name,
            'company'    => sanitize_text_field($company),
            'phone'      => sanitize_text_field((string) $args['phone']),
            'email'      => $email,
            'address'    => wp_kses_post((string) $args['address']),
            'notes'      => wp_kses_post((string) $args['notes']),
            'active'     => !empty($args['active']) ? 1 : 0,
            'updated_at' => current_time('mysql'),
        ];
        $fmt = ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'];

        global $wpdb;
        $t = IEM_Schema::table('suppliers');

        $id = (int) $args['id'];
        if ($id > 0) {
            $exists = self::get($id);
            if (!$exists) {
                return new WP_Error('iem_supplier_not_found', 'Proveedor no encontrado.');
            }
            $ok = $wpdb->update($t, $data, ['id' => $id], $fmt, ['%d']);
            if ($ok === false) {
                return new WP_Error('iem_db_update', 'No se pudo actualizar el proveedor.');
            }
            return $id;
        }

        $data['created_at'] = current_time('mysql');
        $fmt[] = '%s';
        $ok = $wpdb->insert($t, $data, $fmt);
        if (!$ok) {
            return new WP_Error('iem_db_insert', 'No se pudo crear el proveedor.');
        }
        return (int) $wpdb->insert_id;
    }

    /** Soft toggle: activo ↔ inactivo. */
    public static function toggle_active($id)
    {
        $id  = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('iem_supplier_not_found', 'Proveedor no encontrado.');
        }
        global $wpdb;
        $t = IEM_Schema::table('suppliers');
        $new = ((int) $row['active'] === 1) ? 0 : 1;
        $ok = $wpdb->update($t,
            ['active' => $new, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );
        if ($ok === false) {
            return new WP_Error('iem_db_update', 'No se pudo cambiar el estado del proveedor.');
        }
        return $new;
    }
}
