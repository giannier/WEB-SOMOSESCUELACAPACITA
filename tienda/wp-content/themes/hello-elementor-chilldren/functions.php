<?php

// Importar estilos del padre

function importar_estilos() {

    $parent_style = 'parent-style';

    wp_enqueue_style( $parent_style, get_template_directory_uri() . '/style.css' );
    wp_enqueue_style( 'child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array( $parent_style ),
        wp_get_theme()->get('Version')
    );
}
add_action( 'wp_enqueue_scripts', 'importar_estilos' );

/*NOMBRE DE POST*/
function post_title_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'id' => get_the_ID(),
    ), $atts, 'post_title' );

    $post_title = get_the_title( $atts['id'] );
    return $post_title;
}
add_shortcode( 'post_title', 'post_title_shortcode' );




// Paso 1: Vaciar el carrito antes de agregar el nuevo producto
add_filter( 'woocommerce_add_to_cart_validation', 'limpiar_carrito_antes_de_nuevo_item', 10, 2 );
function limpiar_carrito_antes_de_nuevo_item( $passed, $product_id ) {
    if ( ! WC()->cart->is_empty() ) {
        WC()->cart->empty_cart();
    }
    return $passed;
}

// Paso 2: Cambiar la redirección del botón "Añadir al carrito" hacia el Checkout
add_filter( 'woocommerce_add_to_cart_redirect', 'redireccionar_al_checkout' );
function redireccionar_al_checkout() {
    return wc_get_checkout_url();
}

function card_datos_flotantes_shortcode( $atts ) {
    // 1. Configurar el ID (tu lógica de atts)
    $atts = shortcode_atts( array(
        'id' => get_the_ID(),
    ), $atts, 'datos_card' );

    // 2. Obtener los datos reales
    $post_title = get_the_title( $atts['id'] );
    $beneficios = get_field( 'beneficios', $atts['id'] ); // Tu variable ACF

    // Si ACF está vacío, ponemos un texto preventivo
    if ( !$beneficios ) { $beneficios = 'Próximamente más detalles.'; }

    // 3. Devolverlo en contenedores OCULTOS para el JavaScript
    $html = '<div class="datos-para-js" style="display:none;">';
    $html .= '  <span class="js-titulo">' . esc_html( $post_title ) . '</span>';
    $html .= '  <span class="js-beneficios">' . esc_html( $beneficios ) . '</span>';
    $html .= '</div>';

    return $html;
}
add_shortcode( 'datos_card', 'card_datos_flotantes_shortcode' );
function whatsapp_url_shortcode() {
    // Obtenemos el ID del producto o post actual
    $post_id = get_the_ID();
    
    // Si estamos en un maquetador, esto asegura obtener el ID correcto
    if ( is_singular() && !in_the_loop() ) {
        $post_id = get_queried_object_id();
    }

    $post_title = get_the_title( $post_id );
    $phone = "51908860523";
    $mensaje = "Necesito más información del curso: " . $post_title;
    
    // Creamos la URL limpia
    $url = "https://wa.me/" . $phone . "?text=" . rawurlencode($mensaje);
    
    return $url;
}
add_shortcode( 'url_whatsapp', 'whatsapp_url_shortcode' );


