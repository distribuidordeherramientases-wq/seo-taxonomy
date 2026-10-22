<?php
/**
 * Módulo reutilizable de FAQs.
 *
 * Variables esperadas:
 *
 * $faq_object_type
 * $faq_object_id
 * $faq_ambito
 */

defined('ABSPATH') || exit;

global $wpdb;

$faq_object_type = isset($faq_object_type)
    ? absint($faq_object_type)
    : 0;

$faq_object_id = isset($faq_object_id)
    ? absint($faq_object_id)
    : 0;

$faq_ambito = isset($faq_ambito)
    ? sanitize_key($faq_ambito)
    : '';

if(!$faq_object_type || !$faq_object_id){
    return;
}

$faq_table = $wpdb->prefix . 'seo_faq';

if($faq_ambito !== ''){

    $faqs = $wpdb->get_results(

        $wpdb->prepare(

            "SELECT
                id,
                question,
                answer
             FROM {$faq_table}
             WHERE object_type = %d
             AND object_id = %d
             AND ambito = %s
             AND active = 1
             ORDER BY sort_order ASC, id ASC",

            $faq_object_type,
            $faq_object_id,
            $faq_ambito

        )

    );

}else{

    $faqs = $wpdb->get_results(

        $wpdb->prepare(

            "SELECT
                id,
                question,
                answer
             FROM {$faq_table}
             WHERE object_type = %d
             AND object_id = %d
             AND active = 1
             ORDER BY sort_order ASC, id ASC",

            $faq_object_type,
            $faq_object_id

        )

    );

}

if(empty($faqs)){
    return;
}
?>

<section class="taxonomy-faq">

    <div class="taxonomy-container">

        <header class="taxonomy-section-header">

            <h2>
                Preguntas frecuentes
            </h2>

            <p>
                Resolvemos las dudas más habituales sobre esta categoría.
            </p>

        </header>

        <div class="taxonomy-faq-list">

            <?php foreach($faqs as $faq): ?>

                <details
                    class="taxonomy-faq-item"
                    data-faq-id="<?php echo esc_attr($faq->id); ?>"
                >

                    <summary class="taxonomy-faq-question">

                        <?php echo esc_html($faq->question); ?>

                    </summary>

                    <div class="taxonomy-faq-answer">

                        <?php echo wp_kses_post($faq->answer); ?>

                    </div>

                </details>

            <?php endforeach; ?>

        </div>

    </div>

</section>