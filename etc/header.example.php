<?php
/**
 * Custom header injection file
 * This file is included right before the closing </head> tag
 *
 * Copy this file to header.php and customize it for your needs
 * Example: Add Google Analytics, custom meta tags, or other tracking scripts
 */
?>

<!-- Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id=GA_MEASUREMENT_ID"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'GA_MEASUREMENT_ID');
</script>
