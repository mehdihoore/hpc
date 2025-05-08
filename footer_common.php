<?php // public_html/footer_common.php 
?>
</div> <!-- End of .container .main-content-common from header_common.php -->

<button id="scrollToTopBtn" class="hidden" title="برو به بالا"><i class="fas fa-arrow-up"></i></button>

<footer class="footer-common mt-auto">
    <div class="container">
        <span>© <?php echo date("Y"); ?> سامانه مدیریت آلومنیوم شیشه تهران. تمامی حقوق محفوظ است.</span>
    </div>
</footer>

<!-- Bootstrap Bundle JS (includes Popper) - Essential for dropdowns, collapse, etc. -->
<script src="/assets/js/bootstrap.bundle.min.js"></script>

<?php
// Allow pages to add their own specific JS files
if (isset($extra_js) && is_array($extra_js)) {
    foreach ($extra_js as $js_file) {
        echo '<script src="' . escapeHtml($js_file) . '"></script>';
    }
}
?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const scrollToTopButton = document.getElementById('scrollToTopBtn');

        if (scrollToTopButton) { // Check if the button exists on the page
            // Show/Hide button based on scroll position
            window.onscroll = function() {
                if (document.body.scrollTop > 100 || document.documentElement.scrollTop > 100) { // Show after 100px scroll
                    scrollToTopButton.classList.remove('hidden');
                } else {
                    scrollToTopButton.classList.add('hidden');
                }
            };

            // Smooth scroll to top on click
            scrollToTopButton.addEventListener('click', function() {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
        }
    });
</script>

</body>

</html>