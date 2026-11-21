<?php if (!defined('ABSPATH')) { exit; } ?>
</main>
<footer class="site-footer">
  <div class="wrap footer-in">
    <div>
      <div class="footer-brand">
        <img src="<?php echo esc_url(ERA_LOGO_URL); ?>" alt="Apparel is How Eye Roll logo" width="600" height="600">
        <div>
          <h4>APPAREL IS HOW EYE ROLL</h4>
          <p style="color:var(--muted);margin:0">Funny shirts, desk mats &amp; gifts for dads, programmers, and proud 90s kids. Printed on demand, shipped worldwide.</p>
        </div>
      </div>
    </div>
    <div>
      <h4>SHOP</h4>
      <ul>
        <li><a href="<?php echo esc_url(home_url('/shop/')); ?>">All products</a></li>
        <li><a href="<?php echo esc_url(home_url('/product-category/dad-jokes/')); ?>">Dad jokes</a></li>
        <li><a href="<?php echo esc_url(home_url('/product-category/programmer-humor/')); ?>">Programmer humor</a></li>
        <li><a href="<?php echo esc_url(home_url('/product-category/child-of-the-90s/')); ?>">Child of the 90s</a></li>
        <li><a href="<?php echo esc_url(home_url('/blog/')); ?>">Blog</a></li>
        <li><a href="<?php echo esc_url(home_url('/cart/')); ?>">Cart</a></li>
        <li><a href="<?php echo esc_url(home_url('/my-account/')); ?>">My account</a></li>
      </ul>
    </div>
    <div>
      <h4>HELP</h4>
      <ul>
        <li><a href="<?php echo esc_url(home_url('/contact/')); ?>">Contact us</a></li>
        <li><a href="<?php echo esc_url(home_url('/shipping-policy/')); ?>">Shipping policy</a></li>
        <li><a href="<?php echo esc_url(home_url('/refund-policy/')); ?>">Refund &amp; returns</a></li>
        <li><a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>">Privacy policy</a></li>
        <li><a href="<?php echo esc_url(home_url('/terms-and-conditions/')); ?>">Terms &amp; conditions</a></li>
      </ul>
    </div>
    <div>
      <h4>FOLLOW THE EYE ROLL</h4>
      <ul>
        <li><a href="https://www.instagram.com/apparelishoweyer">Instagram &mdash; @apparelishoweyer</a></li>
        <li><a href="https://www.tiktok.com/@chancemcox">TikTok &mdash; @chancemcox</a></li>
        <li><a href="<?php echo esc_url(ERA_ETSY_SHOP); ?>">Etsy &mdash; ApparelisHowEyeRollU</a></li>
      </ul>
    </div>
  </div>
  <div class="colophon">&copy; <?php echo esc_html(date('Y')); ?> Apparel is How Eye Roll &mdash; apparelishoweyeroll.com</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
