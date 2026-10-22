<?php
/**
 * Formulario reutilizable para enviar preguntas.
 *
 * Variables esperadas:
 *
 * $faq_form_object_type
 * $faq_form_object_id
 * $faq_form_ambito
 */

defined('ABSPATH') || exit;

$faq_form_object_type = isset($faq_form_object_type)
    ? absint($faq_form_object_type)
    : 0;

$faq_form_object_id = isset($faq_form_object_id)
    ? absint($faq_form_object_id)
    : 0;

$faq_form_ambito = isset($faq_form_ambito)
    ? sanitize_key($faq_form_ambito)
    : '';

$dht_us_market = function_exists('dht_template_is_us_market') && dht_template_is_us_market();
?>

<section class="taxonomy-faq-form">

    <div class="taxonomy-container">

        <div class="taxonomy-faq-form-box">

            <header class="taxonomy-section-header">

                <h2>
                    <?php echo esc_html($dht_us_market ? 'Have a question?' : '¿Tienes alguna pregunta?'); ?>
                </h2>

                <p>
                    <?php echo esc_html($dht_us_market ? 'Send us your question and our team will try to help.' : 'Envíanos tu consulta y nuestro equipo intentará ayudarte.'); ?>
                </p>

            </header>

            <form
                class="seo-faq-form"
                method="post"
            >

                <?php
                wp_nonce_field(
                    'seo_submit_faq_question',
                    'seo_faq_question_nonce'
                );
                ?>

                <input
                    type="hidden"
                    name="action"
                    value="seo_submit_faq_question"
                >

                <input
                    type="hidden"
                    name="object_type"
                    value="<?php echo esc_attr($faq_form_object_type); ?>"
                >

                <input
                    type="hidden"
                    name="object_id"
                    value="<?php echo esc_attr($faq_form_object_id); ?>"
                >

                <input
                    type="hidden"
                    name="ambito"
                    value="<?php echo esc_attr($faq_form_ambito); ?>"
                >

                <div class="seo-faq-form-field">

                    <label for="seo-faq-name">
                        <?php echo esc_html($dht_us_market ? 'Name' : 'Nombre'); ?>
                    </label>

                    <input
                        id="seo-faq-name"
                        type="text"
                        name="customer_name"
                        autocomplete="name"
                        required
                    >

                </div>

                <div class="seo-faq-form-field">

                    <label for="seo-faq-email">
                        <?php echo esc_html($dht_us_market ? 'Email' : 'Correo electrónico'); ?>
                    </label>

                    <input
                        id="seo-faq-email"
                        type="email"
                        name="customer_email"
                        autocomplete="email"
                        required
                    >

                </div>

                <div class="seo-faq-form-field">

                    <label for="seo-faq-question">
                        <?php echo esc_html($dht_us_market ? 'Question' : 'Pregunta'); ?>
                    </label>

                    <textarea
                        id="seo-faq-question"
                        name="customer_question"
                        rows="5"
                        required
                    ></textarea>

                </div>

                <div class="seo-faq-form-privacy">

                    <label>

                        <input
                            type="checkbox"
                            name="privacy_accepted"
                            value="1"
                            required
                        >

                        <?php echo esc_html($dht_us_market ? 'I have read and accept the Privacy Policy.' : 'He leído y acepto la política de privacidad.'); ?>

                    </label>

                </div>

                <button
                    type="submit"
                    class="seo-faq-form-button"
                >
                    <?php echo esc_html($dht_us_market ? 'Send question' : 'Enviar pregunta'); ?>
                </button>

                <div
                    class="seo-faq-form-message"
                    aria-live="polite"
                ></div>

            </form>

        </div>

    </div>

</section>