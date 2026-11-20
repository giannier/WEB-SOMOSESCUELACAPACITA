<?php
/**
 * Correo de nueva inscripción (HTML) — Personalizado
 * 
 * Ubicación: yourtheme/moowoodle/emails/html/EnrollmentEmail.php
 */

defined( 'ABSPATH' ) || exit();

do_action( 'woocommerce_email_header', $email_heading );
$user = get_user_by( 'email', $args['user_email'] );
?>

<p>Hola <?php echo esc_html( $user->first_name ?? '' ); ?>,</p>

<p>
    ¡Bienvenido a <strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong>!  
    Nos alegra tenerte con nosotros.
</p>

<p>
    Tu cuenta en nuestro <strong>Aula Virtual</strong> ha sido creada exitosamente.  
    A continuación encontrarás tus datos de acceso:
</p>

<h3>Información de acceso</h3>

<p>
    <strong>Aula Virtual:</strong>  
    <a href="https://aula.iusaca.edu.pe/" target="_blank">
        https://aula.iusaca.edu.pe/
    </a><br>

    <strong>Usuario (correo):</strong> <?php echo esc_html( $user->user_email ?? '' ); ?><br>
</p>

<?php
$moodle_pwd     = get_user_meta( $user->ID ?? 0, 'moowoodle_moodle_user_pwd', true );
$moodle_created = get_user_meta( $user->ID ?? 0, 'moowoodle_moodle_new_user_created', true );

if ( $moodle_created ) : ?>
    <p>
        <strong>Contraseña :</strong> <?php echo esc_html( $moodle_pwd ); ?><br>
        <em>Por seguridad, deberás cambiar tu contraseña en tu primer inicio de sesión.</em>
    </p>
<?php endif; ?>

<h3>Detalles de tu inscripción</h3>

<?php if ( ! empty( $args['enrollments']['course'] ) ) : ?>
    <p><strong>Curso(s) matriculado(s):</strong></p>
    <ul>
        <?php foreach ( $args['enrollments']['course'] as $product_id => $product_name ) : ?>
            <li><?php echo esc_html( $product_name ); ?></li>
        <?php endforeach; ?>
    </ul>
<?php else : ?>
    <ul><li>Curso </li></ul>
<?php endif; ?>

<h3>Accede a tus cursos</h3>

<p>
    Para comenzar, ingresa al aula virtual y dirígete a la sección <strong>“Mis Cursos”</strong>.<br>
    👉 <a href="https://aula.iusaca.edu.pe/" target="_blank">
        https://aula.iusaca.edu.pe/
    </a>
</p>

<p>
    Si tienes cualquier duda o dificultad para ingresar, contáctanos a  
    <a href="mailto:info@iusaca.edu.pe">info@iusaca.edu.pe</a>.
</p>

<p>
    Te deseamos una excelente experiencia de aprendizaje.<br>
    <strong>Equipo de IUSACA</strong>
</p>

