<?php
/**
 * VAPE CO. — Terms & Policy.
 * Vape_ShopWEb / terms.php
 */

require_once __DIR__ . '/includes/functions.php';

$pageTitle       = 'Terms & Policy — ' . APP_NAME;
$bodyClass       = 'page-legal';
$metaDescription = 'Terms, age policy, payment, shipping, and privacy information for ' . APP_NAME . '.';
$loadApp         = false;

require_once __DIR__ . '/includes/head.php';
?>
<main class="legal">
  <div class="legal__container">
    <p class="micro-label">Legal</p>
    <h1 class="legal__title">Terms &amp; Policy</h1>
    <p class="legal__meta">Last updated: November 16, 2026 · Version <?= e(TERMS_VERSION) ?></p>

    <nav class="legal__toc" aria-label="On this page">
      <ol>
        <li><a href="#acceptance">Acceptance</a></li>
        <li><a href="#age">Age requirement</a></li>
        <li><a href="#products">Products and nicotine warning</a></li>
        <li><a href="#accounts">Accounts and security</a></li>
        <li><a href="#orders">Orders and pricing</a></li>
        <li><a href="#payment">Payment</a></li>
        <li><a href="#shipping">Shipping and delivery</a></li>
        <li><a href="#returns">Returns, defects, and warranty</a></li>
        <li><a href="#prohibited">Prohibited use</a></li>
        <li><a href="#privacy">Privacy</a></li>
        <li><a href="#liability">Liability</a></li>
        <li><a href="#changes">Changes to these terms</a></li>
        <li><a href="#contact">Contact</a></li>
        <li><a href="#governing-law">Governing law</a></li>
      </ol>
    </nav>

    <section class="legal__section" id="acceptance">
      <h2>1. Acceptance</h2>
      <p>By creating an account, accessing, or ordering from <?= e(APP_NAME) ?>, you agree to these Terms &amp; Policy. If you do not agree, do not use the site or place an order.</p>
    </section>

    <section class="legal__section" id="age">
      <h2>2. Age requirement</h2>
      <p>You must be at least 21 years old to browse, register, or buy. We may ask for a valid government ID or other proof of age at any time. If we believe an account belongs to a minor, we may suspend the account and cancel orders. Nicotine products are not for minors, non-smokers, pregnant or breastfeeding people, or people with heart conditions.</p>
    </section>

    <section class="legal__section" id="products">
      <h2>3. Products and nicotine warning</h2>
      <ul>
        <li>Products may contain nicotine, which is addictive.</li>
        <li>Keep products and e-liquids away from children and pets.</li>
        <li>Use devices and batteries according to the manufacturer’s instructions. Do not modify, short-circuit, or use damaged batteries.</li>
        <li>Product images are for illustration. Actual packaging, color, and batch may vary.</li>
        <li>We do not provide medical advice. Consult a healthcare professional if you have concerns.</li>
      </ul>
    </section>

    <section class="legal__section" id="accounts">
      <h2>4. Accounts and security</h2>
      <ul>
        <li>Provide accurate, current information.</li>
        <li>You are responsible for keeping your password confidential and for activity under your account.</li>
        <li>Do not share your account or create accounts on behalf of minors.</li>
        <li>Tell us immediately if you suspect unauthorized access.</li>
      </ul>
    </section>

    <section class="legal__section" id="orders">
      <h2>5. Orders and pricing</h2>
      <ul>
        <li>Prices are in Philippine pesos and include VAT. Shipping is shown separately at checkout.</li>
        <li>We may correct pricing or availability errors and cancel or refund an affected order.</li>
        <li>An order is confirmed only after we accept it. Stock is not reserved until the order is placed.</li>
        <li>We may refuse or cancel orders that appear fraudulent, abusive, or prohibited by law.</li>
      </ul>
    </section>

    <section class="legal__section" id="payment">
      <h2>6. Payment</h2>
      <ul>
        <li>We accept GCash, Maya, and Cash.</li>
        <li>Split payment is allowed on one order using two different methods. The amounts must add up to the order total.</li>
        <li>We do not accept card or bank transfer payments on this site.</li>
        <li>For e-wallet payments, follow the payment instructions shown after checkout. For cash, prepare the amount due on delivery.</li>
        <li>We are not responsible for fees charged by your e-wallet provider.</li>
      </ul>
    </section>

    <section class="legal__section" id="shipping">
      <h2>7. Shipping and delivery</h2>
      <ul>
        <li>Deliveries are made to the address you provide. Check it carefully; we are not responsible for delays or losses caused by an incorrect address.</li>
        <li>Delivery estimates are not guarantees. Weather, courier volume, and local restrictions may cause delays.</li>
        <li>Risk passes to you on delivery. If a package is damaged, report it within 48 hours with photos.</li>
      </ul>
    </section>

    <section class="legal__section" id="returns">
      <h2>8. Returns, defects, and warranty</h2>
      <ul>
        <li>Report damaged, missing, or incorrect items within 48 hours of delivery.</li>
        <li>For hygiene and safety reasons, opened e-liquids, pods, cartridges, and disposable devices are not returnable unless defective.</li>
        <li>Defective devices are covered by the manufacturer’s warranty, if any. Contact us with your order number and a description of the issue.</li>
        <li>We do not accept returns for change of mind.</li>
      </ul>
    </section>

    <section class="legal__section" id="prohibited">
      <h2>9. Prohibited use</h2>
      <p>You may not:</p>
      <ul>
        <li>Buy for or supply products to anyone under 21.</li>
        <li>Resell products without written permission.</li>
        <li>Use the site for fraud, harassment, or unlawful activity.</li>
        <li>Attempt to bypass age verification, security controls, or payment validation.</li>
      </ul>
    </section>

    <section class="legal__section" id="privacy">
      <h2>10. Privacy</h2>
      <p>We collect the information needed to run the shop: name, email, phone, birthdate, delivery address, order history, and technical data such as IP address and session data. We use it to:</p>
      <ul>
        <li>Verify age and eligibility.</li>
        <li>Process and deliver orders.</li>
        <li>Provide support and prevent fraud.</li>
        <li>Comply with legal obligations.</li>
      </ul>
      <p>We do not sell personal data. We share only what is needed with couriers and payment providers. We keep records for as long as needed for operations and legal compliance. You may request access or correction of your information through the contact channel below. The site uses cookies, localStorage, and sessionStorage for sign-in, cart, and age-verification state.</p>
    </section>

    <section class="legal__section" id="liability">
      <h2>11. Liability</h2>
      <p>To the extent allowed by law, <?= e(APP_NAME) ?> is not liable for indirect or consequential losses, misuse of products, battery misuse, or losses caused by inaccurate information you provide. Nothing in these terms limits rights that cannot be limited under Philippine law.</p>
    </section>

    <section class="legal__section" id="changes">
      <h2>12. Changes to these terms</h2>
      <p>We may update these terms. The “Last updated” date and version will change. Continued use after an update means you accept the new version. If you do not agree, stop using the site and contact us to close your account.</p>
    </section>

    <section class="legal__section" id="contact">
      <h2>13. Contact</h2>
      <div class="legal__contact">
        <p>Questions, requests, or complaints:</p>
        <ul>
          <li><strong>Email:</strong> <code>support@vapeco.ph</code> (replace with the real support inbox before launch)</li>
          <li><strong>Phone:</strong> <code>+63 917 000 0000</code> (replace with the real support number before launch)</li>
          <li><strong>Address:</strong> Metro Manila, Philippines (replace with the real business address before launch)</li>
        </ul>
      </div>
    </section>

    <section class="legal__section" id="governing-law">
      <h2>14. Governing law</h2>
      <p>These terms are governed by the laws of the Republic of the Philippines.</p>
    </section>
  </div>
</main>
<?php require_once __DIR__ . '/includes/foot.php'; ?>
