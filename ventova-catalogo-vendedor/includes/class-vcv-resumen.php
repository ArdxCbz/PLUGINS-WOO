<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resumen de venta: el texto que la vendedora copia y pega en WhatsApp.
 *
 * El problema que resuelve: las descripciones de producto son HTML con estilos
 * en línea, tarjetas flex y adornos. Al seleccionarlas y copiarlas desde el
 * navegador, WhatsApp —que no acepta HTML— recibe el flujo del DOM en cadena,
 * sin saltos de línea y con los adornos mezclados.
 *
 * Aquí se guarda una versión en TEXTO PLANO con formato WhatsApp (`*negrita*`,
 * viñetas `•`), redactada una vez por producto, con tokens que se resuelven en
 * el momento de copiar para que el precio y el enlace nunca queden viejos.
 */
class VCV_Resumen
{
    const META_KEY   = '_vcv_resumen';
    const NONCE      = 'vcv_resumen_nonce';
    const AJAX_REGEN = 'vcv_regenerar_resumen';

    /**
     * Borrador derivado automáticamente de la descripción, y hash del HTML del
     * que salió. El hash es lo que permite detectar que la descripción cambió y
     * volver a derivar, sin tener que engancharse a cada forma de editarla
     * (editor, importación CSV, actualización masiva, REST).
     */
    const META_AUTO = '_vcv_resumen_auto';
    const META_HASH = '_vcv_resumen_hash';

    /** Plantilla por defecto cuando un producto no tiene resumen redactado. */
    const FALLBACK = "*{nombre}*\n\n💵 {precio}\n🔗 {enlace}";

    /** De dónde salió la plantilla que se está usando. */
    const ORIGEN_MANUAL   = 'manual';
    const ORIGEN_AUTO     = 'auto';
    const ORIGEN_FALLBACK = 'fallback';

    /** Tokens admitidos y su descripción, para la leyenda del metabox. */
    public static function tokens()
    {
        return [
            '{nombre}'   => __('Nombre del producto', 'ventova-catalogo-vendedor'),
            '{precio}'   => __('Precio vigente (o rango si hay variaciones)', 'ventova-catalogo-vendedor'),
            '{enlace}'   => __('Enlace a la ficha del producto', 'ventova-catalogo-vendedor'),
            '{sku}'      => __('SKU del producto', 'ventova-catalogo-vendedor'),
            '{sucursal}' => __('Sucursal asignada a quien copia', 'ventova-catalogo-vendedor'),
            '{stock}'    => __('Disponibilidad por sucursal', 'ventova-catalogo-vendedor'),
        ];
    }

    public static function init()
    {
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('save_post_product', [__CLASS__, 'save'], 10, 2);
        add_action('wp_ajax_' . self::AJAX_REGEN, [__CLASS__, 'ajax_regenerar']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Metabox
    // ─────────────────────────────────────────────────────────────────────

    public static function add_meta_box()
    {
        // Solo quien administra redacta el resumen. Las vendedoras no llegan a
        // esta pantalla: el tema hijo les retira el menú de productos.
        if (!VCV_Permisos::is_admin_user()) {
            return;
        }

        add_meta_box(
            'vcv-resumen',
            __('Descripción resumida (para copiar y enviar)', 'ventova-catalogo-vendedor'),
            [__CLASS__, 'render_meta_box'],
            'product',
            'normal',
            'high'
        );
    }

    public static function render_meta_box($post)
    {
        $valor = (string) get_post_meta($post->ID, self::META_KEY, true);

        wp_nonce_field(self::NONCE, self::NONCE);

        echo '<p class="description">'
            . esc_html__('Descripción optimizada para redes sociales: es exactamente lo que las vendedoras copian con un clic desde el catálogo. Texto plano — WhatsApp no acepta HTML; usa *asteriscos* para negrita y • para viñetas.', 'ventova-catalogo-vendedor')
            . '</p>';

        echo '<textarea id="vcv-resumen-texto" name="' . esc_attr(self::META_KEY) . '"'
            . ' rows="18" class="widefat code" style="font-family:inherit">'
            . esc_textarea($valor) . '</textarea>';

        // Referencia de longitud, no límite: un mensaje de WhatsApp que se lee
        // de un vistazo ronda los 600–900 caracteres.
        echo '<p class="vcv-resumen-contador" id="vcv-resumen-contador"></p>';

        echo '<p class="vcv-resumen-acciones">';
        echo '<button type="button" class="button" id="vcv-regenerar" data-product="' . (int) $post->ID . '"'
            . ' data-nonce="' . esc_attr(wp_create_nonce(self::AJAX_REGEN)) . '">'
            . esc_html__('Rellenar desde la descripción larga', 'ventova-catalogo-vendedor') . '</button> ';
        echo '<button type="button" class="button" id="vcv-previsualizar">'
            . esc_html__('Vista previa', 'ventova-catalogo-vendedor') . '</button> ';
        echo '<span id="vcv-resumen-estado" class="vcv-resumen-estado"></span>';
        echo '</p>';

        echo '<div id="vcv-resumen-preview" class="vcv-resumen-preview" hidden></div>';

        // Leyenda de tokens.
        echo '<p class="description"><strong>'
            . esc_html__('Tokens (se reemplazan al copiar):', 'ventova-catalogo-vendedor')
            . '</strong></p><ul class="vcv-tokens">';
        foreach (self::tokens() as $token => $desc) {
            echo '<li><code>' . esc_html($token) . '</code> — ' . esc_html($desc) . '</li>';
        }
        echo '</ul>';

        // Qué se copia HOY para este producto. Sin esto, un campo vacío no dice
        // si detrás hay un borrador derivado o solo nombre, precio y enlace.
        $efectiva = self::plantilla($post->ID, ['manual' => $valor]);

        if ($efectiva['origen'] === self::ORIGEN_MANUAL) {
            $aviso = __('Se copia esta descripción resumida. ✔', 'ventova-catalogo-vendedor');
        } elseif ($efectiva['origen'] === self::ORIGEN_AUTO) {
            $aviso = __('Este producto todavía NO tiene descripción resumida. Mientras el campo siga vacío se copia un borrador derivado de la descripción larga, que suele ser demasiado largo para enviar.', 'ventova-catalogo-vendedor');
        } else {
            $aviso = __('Este producto no tiene descripción resumida ni descripción larga: solo se copian nombre, precio y enlace.', 'ventova-catalogo-vendedor');
        }

        echo '<p class="description"><strong>' . esc_html__('En uso:', 'ventova-catalogo-vendedor')
            . '</strong> ' . esc_html($aviso) . '</p>';

        if ($efectiva['origen'] !== self::ORIGEN_MANUAL) {
            echo '<details class="vcv-resumen-efectiva"><summary>'
                . esc_html__('Ver lo que se copia ahora', 'ventova-catalogo-vendedor')
                . '</summary><pre>'
                . esc_html(self::resolve($efectiva['texto'], self::context_from_product($post->ID)))
                . '</pre></details>';
        }
    }

    public static function save($post_id, $post)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST[self::NONCE])), self::NONCE)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (!isset($_POST[self::META_KEY])) {
            return;
        }

        $valor = sanitize_textarea_field(wp_unslash($_POST[self::META_KEY]));

        if ($valor === '') {
            delete_post_meta($post_id, self::META_KEY);
        } else {
            update_post_meta($post_id, self::META_KEY, $valor);
        }
    }

    public static function enqueue($hook)
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }
        if (get_post_type() !== 'product' || !VCV_Permisos::is_admin_user()) {
            return;
        }

        wp_enqueue_style('vcv-admin-resumen', VCV_URL . 'assets/admin-resumen.css', [], VCV_VERSION);
        wp_enqueue_script('vcv-admin-resumen', VCV_URL . 'assets/admin-resumen.js', [], VCV_VERSION, true);
        wp_localize_script('vcv-admin-resumen', 'vcvResumen', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'action'     => self::AJAX_REGEN,
            'generando'  => __('Generando…', 'ventova-catalogo-vendedor'),
            'confirmar'  => __('Esto reemplazará la descripción resumida actual por un borrador nuevo. ¿Continuar?', 'ventova-catalogo-vendedor'),
            'listo'      => __('Borrador cargado. Recórtalo y guarda el producto.', 'ventova-catalogo-vendedor'),
            'error'      => __('No se pudo generar.', 'ventova-catalogo-vendedor'),
            'vacio'      => __('La descripción del producto está vacía.', 'ventova-catalogo-vendedor'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // AJAX
    // ─────────────────────────────────────────────────────────────────────

    public static function ajax_regenerar()
    {
        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $nonce      = isset($_POST['nonce']) ? sanitize_key(wp_unslash($_POST['nonce'])) : '';

        if (!$product_id || !wp_verify_nonce($nonce, self::AJAX_REGEN)) {
            wp_send_json_error(['message' => __('Petición no válida.', 'ventova-catalogo-vendedor')], 400);
        }
        if (!current_user_can('edit_post', $product_id)) {
            wp_send_json_error(['message' => __('Sin permiso.', 'ventova-catalogo-vendedor')], 403);
        }

        $modo = isset($_POST['modo']) ? sanitize_key(wp_unslash($_POST['modo'])) : 'generar';

        if ($modo === 'preview') {
            $plantilla = isset($_POST['plantilla'])
                ? sanitize_textarea_field(wp_unslash($_POST['plantilla']))
                : '';
            if ($plantilla === '') {
                $plantilla = self::FALLBACK;
            }
            wp_send_json_success([
                'texto' => self::resolve($plantilla, self::context_from_product($product_id)),
            ]);
        }

        $html = self::html_fuente($product_id);

        if (trim($html) === '') {
            wp_send_json_error(['message' => __('La descripción del producto está vacía.', 'ventova-catalogo-vendedor')], 404);
        }

        $texto = self::generar_desde_html($html);

        if (trim($texto) === '') {
            wp_send_json_error(['message' => __('No se pudo extraer texto de la descripción.', 'ventova-catalogo-vendedor')], 422);
        }

        wp_send_json_success(['texto' => $texto]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Generador HTML → texto WhatsApp
    // ─────────────────────────────────────────────────────────────────────

    /** Estado del recorrido del DOM (no reentrante; basta para un producto). */
    private static $lineas = [];
    private static $buffer = '';

    /**
     * Convierte la descripción HTML en un borrador de texto plano.
     *
     * Es un BORRADOR: acierta la estructura (títulos, viñetas, párrafos) pero
     * no puede saber qué partes del HTML son adorno. Se espera que se revise
     * antes de guardar.
     *
     * Reglas: h1–h3 → `*título*` · h4–h6 y li → viñeta `•` · p/div → párrafo ·
     * tr → "Etiqueta: valor". La cabecera decorativa (rótulo en mayúsculas y
     * titular) se descarta porque repite el nombre del producto, que ya se
     * antepone con `{nombre}`.
     */
    public static function generar_desde_html($html)
    {
        $html = (string) $html;

        // Los shortcodes se eliminan: su salida no está disponible aquí y su
        // texto crudo no significa nada para el cliente.
        $html = strip_shortcodes($html);

        if (trim($html) === '') {
            return '';
        }

        $cuerpo = class_exists('DOMDocument')
            ? self::extraer_con_dom($html)
            : self::extraer_con_regex($html);

        $cuerpo = self::limpiar_lineas($cuerpo);

        $cuerpo = self::quitar_encabezado($cuerpo);

        // Sin cuerpo no hay nada que aportar: devolver la plantilla mínima
        // disfrazada de borrador haría creer que la derivación funcionó.
        if (empty($cuerpo)) {
            return '';
        }

        $texto = implode("\n", $cuerpo);

        return "*{nombre}*\n\n" . $texto . "\n\n💵 {precio}\n🔗 {enlace}";
    }

    /**
     * Descarta la cabecera decorativa con la que abren estas descripciones:
     * un "eyebrow" en mayúsculas (del tipo "CÁMARA INCORPORADA • VENTOVA STORE")
     * y el titular, que repite el nombre del producto ya antepuesto con
     * `{nombre}`. Se detiene en cuanto encuentra prosa real, y como mucho
     * revisa las tres primeras líneas para no comerse contenido.
     *
     * @param string[] $lineas
     * @return string[]
     */
    private static function quitar_encabezado(array $lineas)
    {
        $intentos = 3;

        while (!empty($lineas) && $intentos > 0) {
            $primera = $lineas[0];

            if ($primera === '') {
                array_shift($lineas);
                continue;
            }

            $es_titulo = (strpos($primera, '*') === 0);
            // Sin ninguna minúscula y corta: es un rótulo, no una frase.
            $es_rotulo = (self::longitud($primera) <= 80 && !preg_match('/\p{Ll}/u', $primera));

            if (!$es_titulo && !$es_rotulo) {
                break;
            }

            array_shift($lineas);
            $intentos--;
        }

        while (!empty($lineas) && $lineas[0] === '') {
            array_shift($lineas);
        }

        return $lineas;
    }

    /** Longitud en caracteres, sin depender de mbstring. */
    private static function longitud($texto)
    {
        $n = preg_match_all('/./us', (string) $texto);

        return $n === false ? strlen((string) $texto) : $n;
    }

    /** @return string[] */
    private static function extraer_con_dom($html)
    {
        self::$lineas = [];
        self::$buffer = '';

        $dom = new DOMDocument();
        $previo = libxml_use_internal_errors(true);

        // El meta charset evita que libxml interprete el HTML como latin-1 y
        // rompa los acentos. No se usa mb_convert_encoding: mbstring puede no
        // estar disponible.
        //
        // Ojo con LIBXML_HTML_NOIMPLIED: obliga a que el fragmento tenga un
        // único elemento raíz, así que el <meta> se quedaría como raíz y libxml
        // descartaría TODO el HTML que va detrás. Por eso se deja que libxml
        // implique <html>/<body> y se recorre el body.
        $ok = $dom->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        if (!$ok) {
            return self::extraer_con_regex($html);
        }

        $body = $dom->getElementsByTagName('body')->item(0);

        self::recorrer($body ? $body : $dom);
        self::volcar();

        return self::$lineas;
    }

    private static function recorrer(DOMNode $nodo)
    {
        if (!$nodo->hasChildNodes()) {
            return;
        }

        foreach ($nodo->childNodes as $hijo) {
            if ($hijo->nodeType === XML_TEXT_NODE) {
                self::$buffer .= ' ' . $hijo->nodeValue;
                continue;
            }
            if ($hijo->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $tag = strtolower($hijo->nodeName);

            if (in_array($tag, ['script', 'style', 'noscript', 'meta', 'link'], true)) {
                continue;
            }

            if ($tag === 'br') {
                self::volcar();
                continue;
            }

            // Títulos → negrita de WhatsApp, con aire por delante.
            if (in_array($tag, ['h1', 'h2', 'h3'], true)) {
                self::volcar();
                $texto = self::normalizar($hijo->textContent);
                if ($texto !== '') {
                    self::$lineas[] = '';
                    self::$lineas[] = '*' . $texto . '*';
                }
                continue;
            }

            // Subtítulos de tarjeta y listas → viñetas.
            if (in_array($tag, ['h4', 'h5', 'h6', 'li'], true)) {
                self::volcar();
                $texto = self::sin_adornos(self::normalizar($hijo->textContent));
                if ($texto !== '') {
                    self::$lineas[] = '• ' . $texto;
                }
                continue;
            }

            // Filas de tabla → "Etiqueta: valor" en una sola línea. Las
            // especificaciones técnicas se maquetan como <tr><th>…</th><td>…</td></tr>,
            // y tratarlas como bloques sueltos partiría cada dato en dos
            // renglones huérfanos.
            if ($tag === 'tr') {
                self::volcar();

                $celdas = [];
                foreach ($hijo->childNodes as $celda) {
                    if ($celda->nodeType !== XML_ELEMENT_NODE) {
                        continue;
                    }
                    if (!in_array(strtolower($celda->nodeName), ['td', 'th'], true)) {
                        continue;
                    }
                    $texto = self::normalizar($celda->textContent);
                    if ($texto !== '') {
                        $celdas[] = $texto;
                    }
                }

                if (count($celdas) === 2) {
                    self::$lineas[] = $celdas[0] . ': ' . $celdas[1];
                } elseif (!empty($celdas)) {
                    self::$lineas[] = implode(' · ', $celdas);
                }
                continue;
            }

            // Bloques → cortan línea y se recorren.
            if (in_array($tag, ['p', 'div', 'section', 'article', 'header', 'footer',
                                'table', 'thead', 'tbody', 'td', 'th', 'ul', 'ol',
                                'blockquote', 'figure', 'figcaption'], true)) {
                self::volcar();
                self::recorrer($hijo);
                self::volcar();
                continue;
            }

            // En línea (span, strong, a, em…): se acumula en el buffer.
            self::recorrer($hijo);
        }
    }

    /** Cierra la línea en curso. */
    private static function volcar()
    {
        $texto = self::normalizar(self::$buffer);
        self::$buffer = '';

        if ($texto !== '') {
            self::$lineas[] = self::sin_adornos($texto);
        }
    }

    /** Extracción de emergencia si DOMDocument no está disponible. */
    private static function extraer_con_regex($html)
    {
        $html = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<\s*(h1|h2|h3)\b[^>]*>(.*?)</\s*\1\s*>#is', "\n\n[[H]]$2\n", $html);
        $html = preg_replace('#<\s*(h4|h5|h6|li)\b[^>]*>(.*?)</\s*\1\s*>#is', "\n[[B]]$2\n", $html);
        $html = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $html);
        $html = preg_replace('#</\s*(p|div|tr|table|ul|ol|section)\s*>#i', "\n", $html);
        $html = wp_strip_all_tags($html);

        $lineas = [];
        foreach (preg_split('/\R/u', $html) as $linea) {
            $texto = self::normalizar($linea);
            if ($texto === '') {
                $lineas[] = '';
                continue;
            }
            if (strpos($texto, '[[H]]') === 0) {
                $lineas[] = '';
                $lineas[] = '*' . self::normalizar(substr($texto, 5)) . '*';
                continue;
            }
            if (strpos($texto, '[[B]]') === 0) {
                $lineas[] = '• ' . self::sin_adornos(self::normalizar(substr($texto, 5)));
                continue;
            }
            $lineas[] = self::sin_adornos($texto);
        }

        return $lineas;
    }

    /** Colapsa espacios, decodifica entidades y recorta. */
    private static function normalizar($texto)
    {
        $texto = html_entity_decode((string) $texto, ENT_QUOTES, 'UTF-8');
        $texto = str_replace("\xC2\xA0", ' ', $texto); // NBSP
        $texto = preg_replace('/\s+/u', ' ', $texto);

        return trim((string) $texto);
    }

    /** Quita viñetas y checks decorativos del inicio de una línea. */
    private static function sin_adornos($texto)
    {
        return trim((string) preg_replace('/^[\s\x{2713}\x{2714}\x{2705}\x{2605}\x{2606}\x{2022}\x{00B7}\x{25CF}\x{25AA}\-–—»>]+/u', '', $texto));
    }

    /**
     * Colapsa blancos repetidos, quita duplicados consecutivos y recorta los
     * extremos.
     *
     * @param string[] $lineas
     * @return string[]
     */
    private static function limpiar_lineas(array $lineas)
    {
        $salida  = [];
        $anterior = null;

        foreach ($lineas as $linea) {
            $linea = rtrim((string) $linea);

            if ($linea === '') {
                if (!empty($salida) && end($salida) !== '') {
                    $salida[] = '';
                }
                continue;
            }

            // Una línea idéntica a la anterior casi siempre es un adorno
            // repetido del maquetado.
            if ($linea === $anterior) {
                continue;
            }

            $salida[]  = $linea;
            $anterior  = $linea;
        }

        while (!empty($salida) && end($salida) === '') {
            array_pop($salida);
        }
        while (!empty($salida) && $salida[0] === '') {
            array_shift($salida);
        }

        return $salida;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tokens
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Reemplaza los tokens de la plantilla.
     *
     * Los tokens desconocidos se eliminan: es preferible una línea de menos a
     * que un `{loquesea}` termine pegado en el chat de un cliente.
     *
     * @param string $plantilla
     * @param array  $contexto  Salida de context_from_row/context_from_product.
     */
    public static function resolve($plantilla, array $contexto)
    {
        $plantilla = (string) $plantilla;

        if (trim($plantilla) === '') {
            $plantilla = self::FALLBACK;
        }

        $conocidos = array_keys(self::tokens());

        $valor = function ($token) use ($conocidos, $contexto) {
            if (!in_array($token, $conocidos, true)) {
                return ''; // Token no reconocido.
            }
            $clave = trim($token, '{}');

            return isset($contexto[$clave]) ? (string) $contexto[$clave] : '';
        };

        // Se decide línea por línea ANTES de sustituir: una vez reemplazado el
        // token por una cadena vacía ya no hay forma de distinguir "la etiqueta
        // se quedó sin valor" de "la línea siempre fue así".
        //
        // Regla: si una línea contiene tokens y TODOS resuelven a vacío, la
        // línea se descarta entera. Es lo que evita pegarle a un cliente un
        // "📍 Disponible en:" sin nada detrás.
        $salida = [];

        foreach (preg_split('/\R/u', $plantilla) as $linea) {
            $linea = rtrim($linea);

            if (preg_match_all('/\{[a-z_]+\}/u', $linea, $encontrados)) {
                $con_valor = false;

                foreach ($encontrados[0] as $token) {
                    if ($valor($token) !== '') {
                        $con_valor = true;
                        break;
                    }
                }

                if (!$con_valor) {
                    continue;
                }

                $linea = preg_replace_callback('/\{[a-z_]+\}/u', function ($m) use ($valor) {
                    return $valor($m[0]);
                }, $linea);
            }

            $salida[] = rtrim($linea);
        }

        $texto = implode("\n", $salida);
        $texto = preg_replace("/\n{3,}/", "\n\n", $texto);

        return trim($texto);
    }

    /**
     * Contexto de tokens a partir de una fila de VCV_Query (catálogo).
     *
     * @param array  $row
     * @param string $mi_sucursal Slug de la sucursal de quien copia.
     */
    public static function context_from_row(array $row, $mi_sucursal = '')
    {
        return [
            'nombre'   => (string) $row['title'],
            'precio'   => self::precio_plano($row['price_min'], $row['price_max']),
            'enlace'   => (string) $row['permalink'],
            'sku'      => (string) $row['sku'],
            'sucursal' => $mi_sucursal !== '' ? VCV_Sucursales::name($mi_sucursal) : '',
            'stock'    => self::stock_plano($row['lines']),
        ];
    }

    /**
     * Contexto a partir de un ID de producto. Solo se usa en la vista previa
     * del metabox (un producto por petición), donde instanciar el objeto
     * WC_Product no tiene coste relevante.
     */
    public static function context_from_product($product_id)
    {
        $product = wc_get_product($product_id);

        if (!$product) {
            return ['nombre' => '', 'precio' => '', 'enlace' => '', 'sku' => '', 'sucursal' => '', 'stock' => ''];
        }

        $min = $max = null;
        if ($product->is_type('variable')) {
            $min = $product->get_variation_price('min', true);
            $max = $product->get_variation_price('max', true);
        } else {
            $min = $max = $product->get_price();
        }

        return [
            // Mismo origen que el catálogo: si la vista previa usara
            // `$product->get_name()` mostraría el guion crudo de la base y el
            // botón de copiar entregaría el guion largo de `wptexturize`, con
            // lo que la previsualización dejaría de previsualizar.
            'nombre'   => VCV_Query::titulo($product_id),
            'precio'   => self::precio_plano(
                ($min === '' ? null : (float) $min),
                ($max === '' ? null : (float) $max)
            ),
            'enlace'   => get_permalink($product_id),
            'sku'      => $product->get_sku(),
            'sucursal' => VCV_Sucursales::name(VCV_Permisos::user_sucursal()),
            'stock'    => '',
        ];
    }

    /**
     * Precio en texto plano.
     *
     * `wc_price()` devuelve HTML (`<span>`, `<bdi>`, `&nbsp;`), que pegado en
     * WhatsApp se vería como etiquetas sueltas.
     */
    public static function precio_plano($min, $max = null)
    {
        if ($min === null) {
            return '';
        }

        $fmt = function ($n) {
            $html = wc_price($n);
            $txt  = wp_strip_all_tags(html_entity_decode($html, ENT_QUOTES, 'UTF-8'));
            $txt  = str_replace("\xC2\xA0", ' ', $txt);

            return trim(preg_replace('/\s+/u', ' ', $txt));
        };

        if ($max !== null && $max > $min) {
            return $fmt($min) . ' – ' . $fmt($max);
        }

        return $fmt($min);
    }

    /**
     * Disponibilidad legible: "SANTA CRUZ (5) · COCHABAMBA (3)".
     * Agrega las cantidades de todos los colores de una misma sucursal.
     */
    public static function stock_plano(array $lines)
    {
        $por_sucursal = [];

        foreach ($lines as $line) {
            $nombre = (string) $line['sucursal_name'];
            if ($nombre === '') {
                continue;
            }
            if (!isset($por_sucursal[$nombre])) {
                $por_sucursal[$nombre] = 0;
            }
            $por_sucursal[$nombre] += max(0, (int) $line['qty']);
        }

        $partes = [];
        foreach ($por_sucursal as $nombre => $qty) {
            $partes[] = $qty > 0 ? $nombre . ' (' . $qty . ')' : $nombre;
        }

        return implode(' · ', $partes);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Plantilla efectiva
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Plantilla que se usará para copiar, en cascada:
     *
     *   1. **manual** — lo redactado en el metabox. Siempre gana.
     *   2. **auto**   — derivado de la descripción del producto y cacheado en
     *                   `_vcv_resumen_auto`. Se rederiva solo si cambió el HTML
     *                   (se compara el hash guardado con el actual).
     *   3. **fallback** — nombre, precio y enlace, cuando no hay descripción de
     *                   la que derivar nada.
     *
     * El paso 2 es lo que hace utilizable el plugin sin redactar producto por
     * producto: con varios cientos de fichas, exigir un texto a mano para cada
     * una equivale a no tener resúmenes.
     *
     * @param int   $product_id
     * @param array $meta Valores ya leídos (`manual`, `auto`, `hash`) para no
     *                    disparar una consulta por fila del catálogo. Lo que no
     *                    venga se lee del producto.
     * @return array{texto:string,origen:string}
     */
    public static function plantilla($product_id, array $meta = [])
    {
        $product_id = (int) $product_id;

        $manual = array_key_exists('manual', $meta)
            ? (string) $meta['manual']
            : (string) get_post_meta($product_id, self::META_KEY, true);

        if (trim($manual) !== '') {
            return ['texto' => $manual, 'origen' => self::ORIGEN_MANUAL];
        }

        $html = self::html_fuente($product_id);

        if (trim($html) === '') {
            return ['texto' => self::FALLBACK, 'origen' => self::ORIGEN_FALLBACK];
        }

        $hash_actual = md5($html);

        $auto = array_key_exists('auto', $meta)
            ? (string) $meta['auto']
            : (string) get_post_meta($product_id, self::META_AUTO, true);
        $hash_previo = array_key_exists('hash', $meta)
            ? (string) $meta['hash']
            : (string) get_post_meta($product_id, self::META_HASH, true);

        if (trim($auto) !== '' && $hash_previo === $hash_actual) {
            return ['texto' => $auto, 'origen' => self::ORIGEN_AUTO];
        }

        $generado = self::generar_desde_html($html);

        if (trim($generado) === '') {
            return ['texto' => self::FALLBACK, 'origen' => self::ORIGEN_FALLBACK];
        }

        // Se cachea aunque quien mire el catálogo no pueda editar productos: es
        // dato derivado, no entrada de usuario. Solo se escribe la primera vez
        // y cada vez que cambia la descripción.
        if ($product_id > 0) {
            update_post_meta($product_id, self::META_AUTO, $generado);
            update_post_meta($product_id, self::META_HASH, $hash_actual);
        }

        return ['texto' => $generado, 'origen' => self::ORIGEN_AUTO];
    }

    /**
     * HTML del que se deriva el resumen: la descripción larga y, si está vacía,
     * la corta. Los productos que solo traen descripción corta son los que en
     * la prueba salían con la plantilla mínima.
     */
    private static function html_fuente($product_id)
    {
        $post = get_post($product_id);

        if (!$post) {
            return '';
        }

        $html = (string) $post->post_content;

        if (trim($html) === '') {
            $html = (string) $post->post_excerpt;
        }

        return $html;
    }

    /**
     * Igual que plantilla(), partiendo de una fila de VCV_Query (la meta ya
     * viene en la consulta en lote, así que no cuesta consultas extra).
     *
     * @return array{texto:string,origen:string}
     */
    public static function plantilla_para_fila(array $row)
    {
        return self::plantilla(isset($row['id']) ? (int) $row['id'] : 0, [
            'manual' => isset($row['resumen'])      ? (string) $row['resumen']      : '',
            'auto'   => isset($row['resumen_auto']) ? (string) $row['resumen_auto'] : '',
            'hash'   => isset($row['resumen_hash']) ? (string) $row['resumen_hash'] : '',
        ]);
    }

    /** Texto ya resuelto y listo para copiar desde una fila del catálogo. */
    public static function texto_para_fila(array $row, $mi_sucursal = '')
    {
        $plantilla = self::plantilla_para_fila($row);

        return self::resolve($plantilla['texto'], self::context_from_row($row, $mi_sucursal));
    }
}
