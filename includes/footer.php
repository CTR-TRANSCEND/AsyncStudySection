        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> <?php echo escape(APP_NAME); ?>. All rights reserved.</p>
        </div>
    </footer>

    <!-- All JavaScript loaded at end of body for correct initialization order -->
    <script src="<?php echo BASE_URL; ?>/assets/js/utilities.js?v=20260315"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/toast.js?v=20260315"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/modal.js?v=20260315"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/validation.js?v=20260315"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/theme.js?v=20260315"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/nav.js?v=20260315"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js?v=20260315"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/bullet-editor.js?v=20260315"></script>
    <script>
        // Initialize theme toggle button in user menu
        document.addEventListener('DOMContentLoaded', function() {
            var themeContainer = document.getElementById('agr-theme-toggle-container');
            if (themeContainer && typeof AGR !== 'undefined' && AGR.Theme) {
                var toggleBtn = AGR.Theme.createToggleButton({ showLabel: true });
                themeContainer.appendChild(toggleBtn);
            }
        });
    </script>
</body>
</html>
