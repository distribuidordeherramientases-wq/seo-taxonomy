<?php
/**
 * US affiliate storefront footer. Never loads the Spanish sales footer.
 */
defined('ABSPATH') || exit;

$us_privacy_url = get_privacy_policy_url();
?>
<footer class="dht-us-footer" role="contentinfo">
    <div class="dht-us-footer-inner">
        <section>
            <h2>Purchases, shipping and returns</h2>
            <p>Purchases and payments are completed on the retailer's website.
               Delivery, returns and after-sales support are provided by the
               retailer under its terms, not by Distribuidor de Herramientas.</p>
        </section>
        <section>
            <h2>Affiliate disclosure</h2>
            <p>Distribuidor de Herramientas publishes independent product guides.
               We may earn a commission from purchases through our affiliate
               links, at no additional cost to you.</p>
            <?php if ($us_privacy_url) : ?>
                <p><a href="<?php echo esc_url($us_privacy_url); ?>">Privacy policy</a></p>
            <?php endif; ?>
        </section>
        <p class="dht-us-footer-copyright">&copy; <?php echo esc_html(wp_date('Y')); ?>
            Distribuidor de Herramientas · United States</p>
    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
