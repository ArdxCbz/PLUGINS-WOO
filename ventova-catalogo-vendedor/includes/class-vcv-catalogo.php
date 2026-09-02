<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Página del catálogo.
 *
 * Sustituye a `mostrar_pagina_productos_personalizados()` del tema hijo
 * (`admin-cleanup.php:93`). Además de apoyarse en VCV_Query (costo constante
 * en vez de N×M objetos), añade lo que a las vendedoras les faltaba para
 * trabajar sin salirse del backend: buscador, precio y enlace a la ficha.
 */
class VCV_Catalogo
{
    public static function render()
    {
        if (!VCV_Permisos::can_view_catalogo()) {
            wp_die(esc_html__('No tienes permiso para acceder a esta página.', 'ventova-catalogo-vendedor'));
        }

        $args = self::request_args();
        $data = VCV_Query::get_catalog($args);

        // Sucursal de la vendedora: se resalta su badge para que localice de un
        // vistazo lo que puede entregar ella misma.
        $mi_sucursal = VCV_Permisos::user_sucursal();

        echo '<div class="wrap vcv-wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Catálogo', 'ventova-catalogo-vendedor') . '</h1>';

        self::render_filters($args);
        self::render_summary($data);
        self::render_table($data, $args, $mi_sucursal);
        self::render_pagination($data, $args);

        echo '</div>';
    }

    // ─────────────────────────────────────────────────────────────────────

    /** Lee y normaliza los filtros de la URL. */
    private static function request_args()
    {
        $cat = isset($_GET['product_cat']) ? sanitize_text_field(wp_unslash($_GET['product_cat'])) : '';
        // wp_dropdown_categories emite "0" para la opción "todas".
        if ($cat === '0') {
            $cat = '';
        }

        $stock = isset($_GET['vcv_stock']) ? sanitize_text_field(wp_unslash($_GET['vcv_stock'])) : 'instock';
        if (!in_array($stock, ['instock', 'any'], true)) {
            $stock = 'instock';
        }

        // Filtro por presencia de la descripción resumida. Solo tiene sentido
        // para quien la redacta; a la vendedora no se le ofrece.
        $resumen = isset($_GET['vcv_resumen']) ? sanitize_key(wp_unslash($_GET['vcv_resumen'])) : '';
        if (!in_array($resumen, ['sin', 'con'], true) || !VCV_Permisos::is_admin_user()) {
            $resumen = '';
        }

        return [
            'page'        => isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1,
            'per_page'    => VCV_Query::PER_PAGE,
            'search'      => isset($_GET['vcv_s']) ? sanitize_text_field(wp_unslash($_GET['vcv_s'])) : '',
            'product_cat' => $cat,
            'stock'       => $stock,
            'resumen'     => $resumen,
            'orderby'     => 'title',
        ];
    }

    /** Args a conservar al paginar. */
    private static function query_args($args)
    {
        $out = ['page' => VCV_Menu::SLUG];

        if ($args['search'] !== '') {
            $out['vcv_s'] = $args['search'];
        }
        if ($args['product_cat'] !== '') {
            $out['product_cat'] = $args['product_cat'];
        }
        if ($args['stock'] !== 'instock') {
            $out['vcv_stock'] = $args['stock'];
        }
        if (!empty($args['resumen'])) {
            $out['vcv_resumen'] = $args['resumen'];
        }

        return $out;
    }

    private static function render_filters($args)
    {
        echo '<form method="get" class="vcv-filters">';
        echo '<input type="hidden" name="page" value="' . esc_attr(VCV_Menu::SLUG) . '" />';

        // El submenú de admin cuelga de edit.php?post_type=product, así que hay
        // que reponer post_type para no perder el contexto al enviar.
        if (isset($_GET['post_type'])) {
            echo '<input type="hidden" name="post_type" value="'
                . esc_attr(sanitize_key(wp_unslash($_GET['post_type']))) . '" />';
        }

        echo '<input type="search" name="vcv_s" class="vcv-search" value="' . esc_attr($args['search'])
            . '" placeholder="' . esc_attr__('Buscar por nombre o SKU…', 'ventova-catalogo-vendedor') . '" />';

        wp_dropdown_categories([
            'taxonomy'        => 'product_cat',
            'name'            => 'product_cat',
            'value_field'     => 'slug',
            'selected'        => $args['product_cat'],
            'show_option_all' => __('— Todas las categorías —', 'ventova-catalogo-vendedor'),
            'hide_empty'      => true,
            'hierarchical'    => true,
            'orderby'         => 'name',
        ]);

        echo '<select name="vcv_stock">';
        echo '<option value="instock"' . selected($args['stock'], 'instock', false) . '>'
            . esc_html__('Solo con stock', 'ventova-catalogo-vendedor') . '</option>';
        echo '<option value="any"' . selected($args['stock'], 'any', false) . '>'
            . esc_html__('Todos los productos', 'ventova-catalogo-vendedor') . '</option>';
        echo '</select>';

        // Para trabajar el pendiente de redacción. Oculto a las vendedoras: a
        // ellas no les toca saber qué fichas están a medias.
        if (VCV_Permisos::is_admin_user()) {
            $opciones = [
                ''    => __('— Resumen: todos —', 'ventova-catalogo-vendedor'),
                'sin' => __('Sin descripción resumida', 'ventova-catalogo-vendedor'),
                'con' => __('Con descripción resumida', 'ventova-catalogo-vendedor'),
            ];

            echo '<select name="vcv_resumen">';
            foreach ($opciones as $valor => $etiqueta) {
                echo '<option value="' . esc_attr($valor) . '"'
                    . selected($args['resumen'], $valor, false) . '>'
                    . esc_html($etiqueta) . '</option>';
            }
            echo '</select>';
        }

        submit_button(__('Filtrar', 'ventova-catalogo-vendedor'), 'secondary', '', false);

        if ($args['search'] !== '' || $args['product_cat'] !== '' || $args['stock'] !== 'instock'
            || $args['resumen'] !== '') {
            echo ' <a class="button-link vcv-reset" href="'
                . esc_url(add_query_arg(['page' => VCV_Menu::SLUG], admin_url('admin.php')))
                . '">' . esc_html__('Limpiar', 'ventova-catalogo-vendedor') . '</a>';
        }

        echo '</form>';
    }

    private static function render_summary($data)
    {
        if ($data['total'] === 0) {
            return;
        }

        $from = (($data['page'] - 1) * $data['per_page']) + 1;
        $to   = min($data['total'], $from + count($data['rows']) - 1);

        echo '<p class="vcv-summary">';
        printf(
            /* translators: 1: desde, 2: hasta, 3: total */
            esc_html__('Mostrando %1$d–%2$d de %3$d productos', 'ventova-catalogo-vendedor'),
            (int) $from,
            (int) $to,
            (int) $data['total']
        );
        echo '</p>';
    }

    private static function render_table($data, $args, $mi_sucursal)
    {
        $can_edit = VCV_Permisos::is_admin_user();

        echo '<table class="wp-list-table widefat fixed striped vcv-table">';
        echo '<thead><tr>';
        echo '<th class="vcv-col-img">' . esc_html__('Img', 'ventova-catalogo-vendedor') . '</th>';
        echo '<th class="vcv-col-prod">' . esc_html__('Producto', 'ventova-catalogo-vendedor') . '</th>';
        echo '<th class="vcv-col-sku">' . esc_html__('SKU', 'ventova-catalogo-vendedor') . '</th>';
        echo '<th class="vcv-col-price">' . esc_html__('Precio', 'ventova-catalogo-vendedor') . '</th>';
        echo '<th class="vcv-col-stock">' . esc_html__('Stock por sucursal', 'ventova-catalogo-vendedor') . '</th>';
        echo '<th class="vcv-col-copy">' . esc_html__('Copiar', 'ventova-catalogo-vendedor') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($data['rows'])) {
            echo '<tr><td colspan="6" class="vcv-empty">'
                . esc_html__('No se encontraron productos con esos filtros.', 'ventova-catalogo-vendedor')
                . '</td></tr>';
        }

        foreach ($data['rows'] as $row) {
            echo '<tr>';

            // ── Miniatura ────────────────────────────────────────────────
            echo '<td class="vcv-col-img">';
            if ($row['thumb_id'] > 0) {
                echo wp_get_attachment_image($row['thumb_id'], [44, 44]);
            } elseif (function_exists('wc_placeholder_img')) {
                echo wc_placeholder_img([44, 44]);
            }
            echo '</td>';

            // ── Producto ─────────────────────────────────────────────────
            echo '<td class="vcv-col-prod">';
            if ($row['permalink']) {
                printf(
                    '<a class="vcv-title" href="%s" target="_blank" rel="noopener">%s</a>',
                    esc_url($row['permalink']),
                    esc_html($row['title'])
                );
            } else {
                echo '<span class="vcv-title">' . esc_html($row['title']) . '</span>';
            }

            echo '<div class="vcv-actions">';
            if ($row['permalink']) {
                echo '<a href="' . esc_url($row['permalink']) . '" target="_blank" rel="noopener">'
                    . esc_html__('Ver ficha', 'ventova-catalogo-vendedor') . '</a>';
            }
            if ($can_edit) {
                $edit = get_edit_post_link($row['id']);
                if ($edit) {
                    echo ' <span class="vcv-sep">|</span> <a href="' . esc_url($edit) . '">'
                        . esc_html__('Editar', 'ventova-catalogo-vendedor') . '</a>';
                }
            }
            echo '</div>';
            echo '</td>';

            // ── SKU ──────────────────────────────────────────────────────
            echo '<td class="vcv-col-sku">';
            echo $row['sku'] !== ''
                ? '<code>' . esc_html($row['sku']) . '</code>'
                : '<span class="vcv-muted">—</span>';
            echo '</td>';

            // ── Precio ───────────────────────────────────────────────────
            echo '<td class="vcv-col-price">' . self::price_html($row) . '</td>';

            // ── Stock ────────────────────────────────────────────────────
            echo '<td class="vcv-col-stock">' . self::stock_html($row, $mi_sucursal) . '</td>';

            // ── Copiar ───────────────────────────────────────────────────
            echo '<td class="vcv-col-copy">' . self::copy_html($row, $mi_sucursal) . '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /** Precio, o rango si las variaciones difieren. */
    private static function price_html($row)
    {
        if ($row['price_min'] === null) {
            return '<span class="vcv-muted">—</span>';
        }

        if ($row['price_max'] !== null && $row['price_max'] > $row['price_min']) {
            return '<span class="vcv-price">' . wc_price($row['price_min'])
                . ' <span class="vcv-muted">–</span> ' . wc_price($row['price_max']) . '</span>';
        }

        return '<span class="vcv-price">' . wc_price($row['price_min']) . '</span>';
    }

    /**
     * Líneas de stock por sucursal.
     *
     * A diferencia de la página del tema hijo, un producto simple con
     * existencias aparece aquí con su cantidad y su sucursal derivada, en vez
     * del "Sin stock" que se mostraba siempre.
     *
     * Público porque `VCV_Columns` lo reutiliza para la columna "Stock por
     * Sucursal" de la lista de productos: misma lógica, misma presentación y
     * un solo sitio que corregir.
     */
    public static function stock_html($row, $mi_sucursal = '')
    {
        if (empty($row['lines'])) {
            return '<span class="vcv-muted">' . esc_html__('Sin stock', 'ventova-catalogo-vendedor') . '</span>';
        }

        $out = '';
        foreach ($row['lines'] as $line) {
            $mine = ($mi_sucursal !== '' && $line['sucursal_slug'] === $mi_sucursal);

            $out .= '<span class="vcv-line' . ($mine ? ' is-mine' : '') . '">';
            $out .= '<span class="vcv-badge" style="background:' . esc_attr($line['bg']) . '"'
                . ' title="' . esc_attr($line['sucursal_name'] ?: __('Sin sucursal', 'ventova-catalogo-vendedor')) . '">'
                . esc_html($line['label']) . '</span>';

            if ($line['color'] !== '') {
                $out .= '<span class="vcv-color">' . esc_html($line['color']) . '</span>';
            }

            if ($line['qty'] === null) {
                // Variación o producto sin gestión de stock: solo hay estado.
                $out .= '<span class="vcv-qty">'
                    . ($line['stock_status'] === 'instock'
                        ? esc_html__('En stock', 'ventova-catalogo-vendedor')
                        : esc_html__('Sin stock', 'ventova-catalogo-vendedor'))
                    . '</span>';
            } else {
                $out .= '<span class="vcv-qty">(' . (int) $line['qty'] . ')</span>';
            }

            $out .= '</span>';
        }

        if ($row['type'] === 'variable' && count($row['lines']) > 1) {
            $out .= '<span class="vcv-total">' . sprintf(
                /* translators: %d: unidades totales */
                esc_html__('Total: %d', 'ventova-catalogo-vendedor'),
                (int) $row['stock_total']
            ) . '</span>';
        }

        return $out;
    }

    /**
     * Botón de copiado.
     *
     * El texto ya resuelto viaja en la propia página —con 50 filas el peso es
     * asumible— para no hacer un viaje al servidor por cada clic, que en el
     * móvil de la vendedora se nota.
     *
     * Va en un `<textarea>` oculto, NO en un atributo `data-`: el valor de un
     * atributo pasa por la normalización del parser y los saltos de línea no
     * sobreviven de forma fiable. El contenido de un `textarea` es RCDATA y se
     * conserva literal. (Ojo: el parser se come un salto de línea inmediatamente
     * después de la etiqueta de apertura; por eso el texto se escribe pegado y
     * `resolve()` ya lo devuelve sin saltos al inicio.)
     *
     * El botón se marca cuando lo que se copiaría NO es la descripción resumida
     * redactada en la ficha, distinguiendo dos casos: derivado de la descripción
     * larga (`is-auto`) o mínimo por no haber nada de donde sacarlo
     * (`is-fallback`). Sin esa marca, un catálogo a medio redactar se ve idéntico
     * a uno terminado.
     */
    private static function copy_html($row, $mi_sucursal)
    {
        $plantilla = VCV_Resumen::plantilla_para_fila($row);
        $texto     = VCV_Resumen::resolve(
            $plantilla['texto'],
            VCV_Resumen::context_from_row($row, $mi_sucursal)
        );

        if (trim($texto) === '') {
            return '<span class="vcv-muted">—</span>';
        }

        $clases = 'button vcv-copy';

        if ($plantilla['origen'] === VCV_Resumen::ORIGEN_FALLBACK) {
            $clases .= ' is-fallback';
            $titulo  = __('Sin descripción resumida y sin descripción larga: solo se copiará nombre, precio y enlace.', 'ventova-catalogo-vendedor');
        } elseif ($plantilla['origen'] === VCV_Resumen::ORIGEN_AUTO) {
            $clases .= ' is-auto';
            $titulo  = __('Sin descripción resumida: se copiará un borrador derivado de la descripción larga.', 'ventova-catalogo-vendedor');
        } else {
            $titulo = __('Copiar la descripción resumida', 'ventova-catalogo-vendedor');
        }

        return sprintf(
            '<div class="vcv-copy-wrap">'
                . '<button type="button" class="%s" title="%s">'
                . '<span class="dashicons dashicons-clipboard"></span>'
                . '<span class="vcv-copy-label">%s</span></button>'
                . '<textarea class="vcv-copy-src" readonly tabindex="-1" aria-hidden="true">%s</textarea>'
                . '</div>',
            esc_attr($clases),
            esc_attr($titulo),
            esc_html__('Copiar', 'ventova-catalogo-vendedor'),
            esc_textarea($texto)
        );
    }

    private static function render_pagination($data, $args)
    {
        if ($data['pages'] < 2) {
            return;
        }

        $base = admin_url(isset($_GET['post_type']) ? 'edit.php' : 'admin.php');
        $keep = self::query_args($args);

        if (isset($_GET['post_type'])) {
            $keep['post_type'] = sanitize_key(wp_unslash($_GET['post_type']));
        }

        $links = paginate_links([
            'base'      => add_query_arg(array_merge($keep, ['paged' => '%#%']), $base),
            'format'    => '',
            'current'   => $data['page'],
            'total'     => $data['pages'],
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
        ]);

        if ($links) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages vcv-pagination">' . $links . '</div></div>';
        }
    }
}
