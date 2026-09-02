<?php
/**
 * Banco de pruebas de la Fase 2: normalización de filtros y render de celdas.
 */

define('ABSPATH', true);
define('VCV_URL', '');
define('VCV_VERSION', '0.2.0');

// ── Stubs de WordPress ───────────────────────────────────────────────────
function is_wp_error($t) { return false; }
function taxonomy_exists($t) { return in_array($t, ['pa_sucursal', 'pa_color'], true); }
function get_terms($args) {
    if ($args['taxonomy'] === 'pa_sucursal') {
        return [
            (object) ['slug' => 'sucursal-cbba-stock', 'name' => 'Cochabamba'],
            (object) ['slug' => 'sucursal-scz-stock',  'name' => 'Santa Cruz'],
            (object) ['slug' => 'sucursal-lpz-stock',  'name' => 'La Paz'],
        ];
    }
    return [];
}
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s)  { return (string) $s; }
function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return esc_html($s); }
function esc_attr__($s, $d = null) { return esc_attr($s); }
function wp_unslash($s) { return is_string($s) ? stripslashes($s) : $s; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function wc_price($n) { return 'Bs ' . number_format((float) $n, 2); }
function get_user_meta($id, $k, $single = false) { return ''; }
function get_userdata($id) { return null; }
function wp_get_current_user() { return (object) ['ID' => 0, 'roles' => []]; }
function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function strip_shortcodes($s) { return preg_replace('/\[[^\]]*\]/', '', (string) $s); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function get_post($id) { return null; }
function get_post_meta($id, $k, $single = false) { return ''; }
function update_post_meta($id, $k, $v) { return true; }

$base = 'F:/VENTOVA/produccion/complementos/ventova-catalogo-vendedor/includes/';
require_once $base . 'class-vcv-sucursales.php';
require_once $base . 'class-vcv-query.php';
require_once $base . 'class-vcv-permisos.php';
require_once $base . 'class-vcv-menu.php';
require_once $base . 'class-vcv-resumen.php';
require_once $base . 'class-vcv-catalogo.php';

// ── Utilidades ───────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function check($label, $actual, $expected) {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "  OK   $label\n"; return; }
    $fail++;
    echo "  FAIL $label\n";
    echo "       esperado: " . var_export($expected, true) . "\n";
    echo "       obtenido: " . var_export($actual, true) . "\n";
}
function check_contains($label, $haystack, $needle) {
    global $pass, $fail;
    if (strpos($haystack, $needle) !== false) { $pass++; echo "  OK   $label\n"; return; }
    $fail++;
    echo "  FAIL $label\n";
    echo "       no contiene: $needle\n";
    echo "       en: $haystack\n";
}
function check_not_contains($label, $haystack, $needle) {
    global $pass, $fail;
    if (strpos($haystack, $needle) === false) { $pass++; echo "  OK   $label\n"; return; }
    $fail++;
    echo "  FAIL $label — no debia contener: $needle\n";
}
function priv($method, array $args) {
    $r = new ReflectionMethod('VCV_Catalogo', $method);
    $r->setAccessible(true);
    return $r->invokeArgs(null, $args);
}

echo "\n=== 1. Normalizacion de filtros (request_args) ===\n";
$_GET = [];
$a = priv('request_args', []);
check('sin parametros: pagina 1', $a['page'], 1);
check('sin parametros: stock instock', $a['stock'], 'instock');
check('sin parametros: busqueda vacia', $a['search'], '');
check('sin parametros: categoria vacia', $a['product_cat'], '');
check('per_page toma la constante', $a['per_page'], VCV_Query::PER_PAGE);

$_GET = ['product_cat' => '0'];
check('categoria "0" de wp_dropdown_categories -> vacia', priv('request_args', [])['product_cat'], '');

$_GET = ['vcv_stock' => 'basura'];
check('stock invalido -> instock', priv('request_args', [])['stock'], 'instock');

$_GET = ['vcv_stock' => 'any'];
check('stock any se respeta', priv('request_args', [])['stock'], 'any');

$_GET = ['paged' => '-5'];
check('pagina negativa -> 1', priv('request_args', [])['page'], 1);

$_GET = ['paged' => '3'];
check('pagina valida', priv('request_args', [])['page'], 3);

$_GET = ['vcv_s' => '  <b>T83</b> '];
check('busqueda saneada', priv('request_args', [])['search'], 'T83');

echo "\n=== 2. Args conservados al paginar (query_args) ===\n";
$defaults = ['search' => '', 'product_cat' => '', 'stock' => 'instock'];
check('solo el slug cuando todo es por defecto',
    priv('query_args', [$defaults]), ['page' => 'vcv-catalogo']);

check('conserva busqueda y categoria',
    priv('query_args', [['search' => 'T83', 'product_cat' => 'relojes', 'stock' => 'instock']]),
    ['page' => 'vcv-catalogo', 'vcv_s' => 'T83', 'product_cat' => 'relojes']);

check('conserva stock solo si no es el default',
    priv('query_args', [['search' => '', 'product_cat' => '', 'stock' => 'any']]),
    ['page' => 'vcv-catalogo', 'vcv_stock' => 'any']);

echo "\n=== 3. Precio (price_html) ===\n";
check_contains('sin precio -> guion',
    priv('price_html', [['price_min' => null, 'price_max' => null]]), '—');
check_contains('precio unico',
    priv('price_html', [['price_min' => 250.0, 'price_max' => 250.0]]), 'Bs 250.00');
$rango = priv('price_html', [['price_min' => 250.0, 'price_max' => 310.0]]);
check_contains('rango: minimo', $rango, 'Bs 250.00');
check_contains('rango: maximo', $rango, 'Bs 310.00');
check_not_contains('precio unico no pinta rango',
    priv('price_html', [['price_min' => 250.0, 'price_max' => 250.0]]), '–');

echo "\n=== 4. Stock (stock_html) ===\n";
$vacio = ['type' => 'simple', 'stock_total' => 0, 'lines' => []];
check_contains('sin lineas -> Sin stock', priv('stock_html', [$vacio, '']), 'Sin stock');

function linea($slug, $name, $label, $color, $qty, $status = 'instock') {
    return ['variation_id' => 1, 'sucursal_slug' => $slug, 'sucursal_name' => $name,
            'label' => $label, 'bg' => '#000', 'color' => $color, 'qty' => $qty,
            'stock_status' => $status, 'sku' => ''];
}

$row = ['type' => 'variable', 'stock_total' => 8, 'lines' => [
    linea('sucursal-cbba-stock', 'COCHABAMBA', 'CBBA', 'Negro', 3),
    linea('sucursal-scz-stock',  'SANTA CRUZ', 'SCZ',  'Azul Marino', 5),
]];
$html = priv('stock_html', [$row, '']);
check_contains('pinta badge CBBA', $html, '>CBBA<');
check_contains('pinta cantidad', $html, '(3)');
check_contains('pinta color', $html, 'Azul Marino');
check_contains('variable con 2 lineas muestra total', $html, 'Total: 8');
check_not_contains('sin sucursal propia no resalta', $html, 'is-mine');

$html = priv('stock_html', [$row, 'sucursal-scz-stock']);
check_contains('resalta la sucursal de la vendedora', $html, 'is-mine');
check('resalta solo una linea', substr_count($html, 'is-mine'), 1);

$row1 = ['type' => 'simple', 'stock_total' => 4, 'lines' => [
    linea('sucursal-scz-stock', 'SANTA CRUZ', 'SCZ', '', 4),
]];
check_not_contains('simple con una linea no muestra total',
    priv('stock_html', [$row1, '']), 'Total:');

$sin_gestion = ['type' => 'simple', 'stock_total' => 0, 'lines' => [
    linea('sucursal-scz-stock', 'SANTA CRUZ', 'SCZ', '', null, 'instock'),
]];
$html = priv('stock_html', [$sin_gestion, '']);
check_contains('qty null + instock -> En stock', $html, 'En stock');
check_not_contains('qty null no pinta parentesis', $html, '(0)');

$agotado = ['type' => 'simple', 'stock_total' => 0, 'lines' => [
    linea('sucursal-scz-stock', 'SANTA CRUZ', 'SCZ', '', null, 'outofstock'),
]];
check_contains('qty null + outofstock -> Sin stock',
    priv('stock_html', [$agotado, '']), 'Sin stock');

echo "\n=== 5. Contrato compartido con VCV_Columns ===\n";
// La columna del admin reutiliza este render: si deja de ser publica o cambia
// de firma, la lista de productos se rompe sin que nada mas lo note.
$m = new ReflectionMethod('VCV_Catalogo', 'stock_html');
check('stock_html es publica', $m->isPublic(), true);
check('stock_html es estatica', $m->isStatic(), true);
check('mi_sucursal es opcional', $m->getNumberOfRequiredParameters(), 1);
check('se puede llamar sin sucursal',
    strpos(VCV_Catalogo::stock_html(['type' => 'simple', 'stock_total' => 0, 'lines' => []]), 'Sin stock') !== false,
    true);

echo "\n=== 5b. Filtro por descripcion resumida ===\n";
// Solo se ofrece a quien redacta. Sin sesion de admin, se ignora venga como venga.
$_GET = ['vcv_resumen' => 'sin'];
check('vendedora: el filtro se ignora', priv('request_args', [])['resumen'], '');
$_GET = [];
check('sin parametro: vacio', priv('request_args', [])['resumen'], '');

check('no se propaga al paginar si esta vacio',
    priv('query_args', [['search' => '', 'product_cat' => '', 'stock' => 'instock', 'resumen' => '']]),
    ['page' => 'vcv-catalogo']);
check('se propaga al paginar si esta activo',
    priv('query_args', [['search' => '', 'product_cat' => '', 'stock' => 'instock', 'resumen' => 'sin']]),
    ['page' => 'vcv-catalogo', 'vcv_resumen' => 'sin']);

echo "\n=== 6. Transporte del texto a copiar (copy_html) ===\n";
// Regresión: el texto viajaba en un atributo data- y los saltos de línea se
// perdían al leerlo desde el DOM. Debe ir en un <textarea>, que conserva el
// contenido literal.
$fila = [
    'id' => 0, 'title' => 'S70 WIFI', 'sku' => 'S70',
    'permalink' => 'https://ventova.test/s70',
    'price_min' => 460.0, 'price_max' => 460.0,
    'resumen' => "*{nombre}*\n\n• Primera\n• Segunda\n\n💵 {precio}",
    'resumen_auto' => '', 'resumen_hash' => '',
    'lines' => [],
];
$html = priv('copy_html', [$fila, '']);
check_contains('el texto va en un textarea', $html, '<textarea class="vcv-copy-src"');
check_not_contains('ya no viaja en un atributo data-', $html, 'data-vcv-text');
check_contains('conserva los saltos de linea', $html, "*S70 WIFI*\n\n• Primera\n• Segunda");
check_contains('el boton sigue presente', $html, 'class="button vcv-copy"');
check_not_contains('con descripcion resumida no se marca', $html, 'is-fallback');
check_not_contains('tampoco como derivado', $html, 'is-auto');

// Sin descripción resumida NI descripción larga: se marca el caso pobre, para
// que un catálogo a medio redactar no se vea igual que uno terminado.
$sin = array_merge($fila, ['resumen' => '']);
$html = priv('copy_html', [$sin, '']);
check_contains('sin nada que copiar se marca', $html, 'is-fallback');
check_contains('y aun asi copia nombre, precio y enlace', $html, 'S70 WIFI');

echo "\n=== 7. Permisos ===\n";
check('usuario sin sesion no ve el catalogo', VCV_Permisos::can_view_catalogo(), false);
check('usuario sin sesion no es vendedor', VCV_Permisos::is_vendedor(), false);
check('deteccion de codigo legacy del tema hijo', VCV_Permisos::legacy_page_present(), false);

echo "\n────────────────────────────────\n";
echo "PASS: $pass   FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);
