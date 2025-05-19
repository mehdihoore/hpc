<?php
// footer.php
?>
</div> <!-- Close content-wrapper from header -->
</main> <!-- Close main tag from header -->
<footer class="bg-green-800 text-white py-6 mt-auto">
    <div class="max-w-7xl mx-auto px-4">
        <div class="text-center">
            <p class="text-gray-300">تمامی حقوق محفوظ است © <?php echo date('Y'); ?> شرکت آلومینیوم شیشه تهران</p>
        </div>
        <!-- Scroll to Top Button -->
        <button id="scrollToTopBtn" title="برو بالا" class="hidden fixed bottom-5 right-5 bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-full transition duration-300">
            <!-- Up Arrow SVG -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18" />
            </svg>

        </button>
    </div>
</footer>
</div> <!-- Close main-container from header-->
<script>
    // Scroll to Top Button Functionality
    document.addEventListener('DOMContentLoaded', function() {
        const scrollToTopButton = document.getElementById('scrollToTopBtn');

        // Show/Hide button based on scroll position
        window.onscroll = function() {
            if (document.body.scrollTop > 20 || document.documentElement.scrollTop > 20) {
                scrollToTopButton.classList.remove('hidden');
            } else {
                scrollToTopButton.classList.add('hidden');
            }
        };

        // Smooth scroll to top on click
        scrollToTopButton.addEventListener('click', function() {
            // For modern browsers
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });

            // For older browsers (IE/Edge)
            document.body.scrollTop = 0; // For Safari
            document.documentElement.scrollTop = 0; // For Chrome, Firefox, IE, and Opera
        });
    });
</script>
</body>

</html>