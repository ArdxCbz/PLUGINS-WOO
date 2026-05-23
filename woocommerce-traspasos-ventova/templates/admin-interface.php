<?php
/**
 * Template: Interfaz de Administración de Traspasos
 * Variables disponibles: $sucursales, $cats
 */
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$rows = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}wc_tp_history ORDER BY date_created DESC LIMIT 50",
    ARRAY_A
);
?>
<div class="wrap wc-tp-wrap">

    <div class="wc-tp-header">
        <h1>📦 Traspasos de Producto</h1>
        <p class="wc-tp-subtitle">
            Mueve stock entre sucursales o envía bienes con el mismo servicio de mensajería.
        </p>
    </div>

    <h2 class="nav-tab-wrapper wc-tp-tabs">
        <a href="#" class="nav-tab nav-tab-active" data-tab="productos">
            📦 Traspaso de Productos
        </a>
        <a href="#" class="nav-tab" data-tab="bienes">
            🚚 Traspaso de Bienes
        </a>
    </h2>

    <!-- ═══════════ TAB: PRODUCTOS ═══════════ -->
    <div class="wc-tp-tab-content" data-tab="productos">

        <div class="wc-tp-card">
            <div class="wc-tp-card-header">
                <h2><span class="step-num">1</span> Filtrar stock en sucursal Origen</h2>
            </div>
            <div class="wc-tp-card-body">
                <div class="wc-tp-flex">
                    <label>
                        <span class="wc-tp-lbl">Origen</span>
                        <select id="tp_origen" name="origen">
                            <option value="">— selecciona —</option>
                            <?php foreach ($sucursales as $s): ?>
                                <option value="<?php echo esc_attr($s->slug); ?>">
                                    <?php echo esc_html($s->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span class="wc-tp-lbl">Categoría</span>
                        <select id="tp_cat" name="categoria">
                            <option value="">— todas —</option>
                            <?php foreach ($cats as $c): ?>
                                <option value="<?php echo esc_attr($c->term_id); ?>">
                                    <?php echo esc_html($c->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button id="tp_filtrar" class="button">🔍 Filtrar</button>
                </div>
            </div>
        </div>

        <div class="wc-tp-card">
            <div class="wc-tp-card-header">
                <h2><span class="step-num">2</span> Selecciona y carga a la lista</h2>
            </div>
            <div class="wc-tp-card-body">
                <table id="tp_resultados" class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:30px"></th>
                            <th>Producto</th>
                            <th style="width:80px">Stock</th>
                            <th style="width:90px">Cantidad</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <button id="tp_agregar" class="button wc-tp-mt">
                    ➕ Agregar seleccionados
                </button>
            </div>
        </div>

        <div class="wc-tp-card">
            <div class="wc-tp-card-header">
                <h2><span class="step-num">3</span> Lista y envío</h2>
            </div>
            <div class="wc-tp-card-body">
                <table id="tp_lista" class="widefat striped">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th style="width:90px">Cantidad</th>
                            <th style="width:90px"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <div class="wc-tp-flex wc-tp-mt">
                    <label>
                        <span class="wc-tp-lbl">Destino</span>
                        <select id="tp_destino" name="destino">
                            <option value="">— selecciona —</option>
                            <?php foreach ($sucursales as $s): ?>
                                <option value="<?php echo esc_attr($s->slug); ?>">
                                    <?php echo esc_html($s->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span class="wc-tp-lbl">Guía</span>
                        <input type="text" id="tp_guia" name="guia" placeholder="xxxxx-courier">
                    </label>
                    <label>
                        <span class="wc-tp-lbl">Estado</span>
                        <select id="tp_estado" name="estado">
                            <option value="En Curso">En Curso</option>
                            <option value="Recibido">Recibido</option>
                        </select>
                    </label>
                </div>
                <button id="tp_transferir" class="button button-primary button-large wc-tp-mt">
                    🚚 Traspasar inventario
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB: BIENES ═══════════ -->
    <div class="wc-tp-tab-content" data-tab="bienes" style="display:none;">

        <div class="wc-tp-card">
            <div class="wc-tp-card-header">
                <h2>📝 Traspaso de bienes (sin productos del catálogo)</h2>
            </div>
            <div class="wc-tp-card-body">
                <p class="wc-tp-help">
                    Use este modo para enviar bienes que no son productos del catálogo
                    (ej. mobiliario, herramientas, paquetes diversos), aprovechando el
                    mismo servicio de mensajería. <strong>No afecta el stock.</strong>
                </p>

                <div class="wc-tp-flex">
                    <label>
                        <span class="wc-tp-lbl">Origen</span>
                        <select id="tp_b_origen">
                            <option value="">— selecciona —</option>
                            <?php foreach ($sucursales as $s): ?>
                                <option value="<?php echo esc_attr($s->slug); ?>">
                                    <?php echo esc_html($s->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span class="wc-tp-lbl">Destino</span>
                        <select id="tp_b_destino">
                            <option value="">— selecciona —</option>
                            <?php foreach ($sucursales as $s): ?>
                                <option value="<?php echo esc_attr($s->slug); ?>">
                                    <?php echo esc_html($s->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <label class="wc-tp-block wc-tp-mt">
                    <span class="wc-tp-lbl">Descripción del envío</span>
                    <textarea id="tp_b_descripcion" rows="4"
                              placeholder="Detalle del envío: 3 cajas de pintura, 1 silla de oficina, herramientas varias..."></textarea>
                </label>

                <div class="wc-tp-flex wc-tp-mt">
                    <label>
                        <span class="wc-tp-lbl">Guía</span>
                        <input type="text" id="tp_b_guia" placeholder="xxxxx-courier">
                    </label>
                    <label>
                        <span class="wc-tp-lbl">Estado</span>
                        <select id="tp_b_estado">
                            <option value="En Curso">En Curso</option>
                            <option value="Recibido">Recibido</option>
                        </select>
                    </label>
                </div>

                <button id="tp_b_enviar" class="button button-primary button-large wc-tp-mt">
                    🚚 Crear traspaso de bienes
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════ HISTÓRICO ═══════════ -->
    <div class="wc-tp-card wc-tp-mt-lg">
        <div class="wc-tp-card-header">
            <h2>📋 Histórico de traspasos</h2>
        </div>
        <div class="wc-tp-card-body wc-tp-no-pad">
            <table class="widefat striped wc-tp-history" id="tp_history_table">
                <thead>
                    <tr>
                        <th style="width:60px">ID</th>
                        <th style="width:140px">Fecha</th>
                        <th>Usuario</th>
                        <th>Ruta</th>
                        <th>Contenido</th>
                        <th>Guía</th>
                        <th style="width:170px">Estado</th>
                        <th style="width:200px">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center;padding:24px;color:#777;">
                                Aún no hay traspasos registrados.
                            </td>
                        </tr>
                    <?php else: foreach ($rows as $r):
                        $items_arr = json_decode($r['items'] ?? '[]', true) ?: [];
                        $is_desc = empty($items_arr) && !empty($r['descripcion']);
                        $user = get_userdata($r['user_id']);
                        $estado_class = $r['estado'] === 'Recibido' ? 'wc-tp-badge-ok' : 'wc-tp-badge-warn';
                    ?>
                    <tr>
                        <td><strong>#<?php echo esc_html($r['id']); ?></strong></td>
                        <td><?php echo esc_html($r['date_created']); ?></td>
                        <td><?php echo esc_html($user ? $user->user_login : '—'); ?></td>
                        <td>
                            <span class="wc-tp-route">
                                <?php echo esc_html(WC_TP_Mappings::map_sucursal($r['origen'])); ?>
                                <span class="wc-tp-arrow">→</span>
                                <?php echo esc_html(WC_TP_Mappings::map_sucursal($r['destino'])); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($is_desc): ?>
                                <span class="wc-tp-badge wc-tp-badge-bienes">🚚 Bienes</span>
                                <div class="wc-tp-desc-preview" title="<?php echo esc_attr($r['descripcion']); ?>">
                                    <?php echo esc_html(wp_trim_words($r['descripcion'], 12, '…')); ?>
                                </div>
                            <?php else: ?>
                                <span class="wc-tp-badge wc-tp-badge-prod">📦 <?php echo count($items_arr); ?> producto<?php echo count($items_arr) === 1 ? '' : 's'; ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($r['guia']) ?: '<span style="color:#999;">—</span>'; ?></td>
                        <td>
                            <select class="tp_estado_select" data-id="<?php echo esc_attr($r['id']); ?>">
                                <option value="En Curso" <?php selected($r['estado'], 'En Curso'); ?>>En Curso</option>
                                <option value="Recibido" <?php selected($r['estado'], 'Recibido'); ?>>Recibido</option>
                            </select>
                            <span class="wc-tp-badge <?php echo esc_attr($estado_class); ?>"><?php echo esc_html($r['estado']); ?></span>
                        </td>
                        <td class="wc-tp-actions">
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin-ajax.php?action=wc_tp_export_csv&id={$r['id']}"), 'wc_tp_nonce')); ?>" class="button" target="_blank" title="Exportar CSV">CSV</a>
                            <button class="button tp_view_details" data-id="<?php echo esc_attr($r['id']); ?>" title="Ver detalles">🔍</button>
                            <button class="button tp_print_details" data-id="<?php echo esc_attr($r['id']); ?>" title="Imprimir">🖨️</button>
                            <button class="button tp_edit_details" data-id="<?php echo esc_attr($r['id']); ?>" title="Editar">✏️</button>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══════════ MODAL: VER DETALLES ═══════════ -->
    <div id="tp_modal" class="tp_modal_overlay" style="display:none;">
        <div class="tp_modal_content">
            <h2 id="tp_modal_title">Detalles del Traspaso</h2>
            <div id="tp_modal_meta" class="wc-tp-modal-meta"></div>

            <div id="tp_modal_items_section">
                <h3>Productos</h3>
                <table class="widefat striped">
                    <thead>
                        <tr><th>SKU</th><th>Nombre</th><th>Cantidad</th></tr>
                    </thead>
                    <tbody id="tp_modal_items"></tbody>
                </table>
            </div>

            <div id="tp_modal_desc_section" style="display:none;">
                <h3>Descripción</h3>
                <div id="tp_modal_desc_text" class="wc-tp-desc-block"></div>
            </div>

            <div class="tp_modal_actions">
                <button id="tp_modal_close" class="button">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- ═══════════ MODAL: EDITAR ═══════════ -->
    <div id="tp_edit_modal" class="tp_modal_overlay" style="display:none;">
        <div class="tp_modal_content">
            <h2>Editar Traspaso <span id="tp_edit_id_display"></span></h2>
            <input type="hidden" id="tp_edit_id">

            <div class="wc-tp-flex">
                <label class="wc-tp-flex-grow">
                    <span class="wc-tp-lbl">Guía</span>
                    <input type="text" id="tp_edit_guia">
                </label>
            </div>

            <div id="tp_edit_section_productos">
                <hr>
                <h3>Productos <small style="color:#888;font-weight:normal;">(modificar revertirá el stock anterior y aplicará el nuevo)</small></h3>
                <table class="widefat striped">
                    <thead>
                        <tr><th>Producto</th><th style="width:90px">Cantidad</th><th style="width:90px">Acción</th></tr>
                    </thead>
                    <tbody id="tp_edit_items_body"></tbody>
                </table>
                <button id="tp_edit_add_item_btn" class="button wc-tp-mt">➕ Agregar producto (buscar)</button>
                <div id="tp_edit_search_area" style="display:none;">
                    <div class="wc-tp-flex">
                        <input type="text" id="tp_edit_search_input" placeholder="Buscar producto (mín. 3 caracteres)…" class="wc-tp-flex-grow">
                        <button id="tp_edit_search_btn" class="button">Buscar</button>
                    </div>
                    <ul id="tp_edit_search_results"></ul>
                </div>
            </div>

            <div id="tp_edit_section_descripcion" style="display:none;">
                <hr>
                <h3>Descripción del envío</h3>
                <textarea id="tp_edit_descripcion" rows="5" style="width:100%;"></textarea>
            </div>

            <div class="tp_modal_actions">
                <button id="tp_edit_cancel" class="button">Cancelar</button>
                <button id="tp_edit_save" class="button button-primary">Guardar Cambios</button>
            </div>
        </div>
    </div>

</div>
