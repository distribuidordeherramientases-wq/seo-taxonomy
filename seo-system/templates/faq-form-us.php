<?php
/**
 * Reusable form for submitting questions.
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
?>

<section class="taxonomy-faq-form">

    <div class="taxonomy-container">

        <div class="taxonomy-faq-form-box">

            <header class="taxonomy-section-header">

                <h2>
                    Have a question?
                </h2>

                <p>
                    Send us your question and our team will try to help.
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
                        Name
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
                        Email address
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
                        Question
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

                        I have read and accept the Privacy Policy.

                    </label>

                </div>

                <button
                    type="submit"
                    class="seo-faq-form-button"
                >
                    Send question
                </button>

                <div
                    class="seo-faq-form-message"
                    aria-live="polite"
                ></div>

            </form>

        </div>

    </div>

</section>