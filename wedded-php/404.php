<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (!headers_sent()) http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, follow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(base_url()) ?>/assets/css/style.css">
  <title>Page Not Found | <?= h(setting('site_name', 'The Wedded')) ?></title>
</head>
<body>
  <div class="error-page">
    <div>
      <p class="code serif">404</p>
      <h1>This page wandered off the aisle</h1>
      <p>The page you're looking for doesn't exist or may have moved. Let's get you back to somewhere beautiful.</p>
      <div class="hero-actions" style="justify-content:center">
        <a class="btn light" href="<?= h(base_url()) ?>/index.php">Back to Home</a>
        <a class="btn light" href="<?= h(base_url()) ?>/collection.php">View Collection</a>
      </div>
    </div>
  </div>
</body>
</html>
