<?php
// partials/footer.php
declare(strict_types=1);

if (!isset($footerNote)) {
  $footerNote = "School project. Not affiliated with Wizards of the Coast.";
}
?>
<footer style="border-top:1px solid var(--border); color:var(--muted); padding:14px 0; margin-top:12px;">
  <small><?= h((string)$footerNote) ?></small>
</footer>