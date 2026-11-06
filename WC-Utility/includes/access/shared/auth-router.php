<?php


add_shortcode('woodcrest_login_form', function() {
    ob_start(); ?>
    <div class="woodcrest-login-container" data-login-role="tenant">
        <div class="woodcrest-login-card">
            <button type="button" class="woodcrest-login-appearance" data-wc-theme-toggle aria-pressed="false" aria-label="Switch to dark mode" title="Switch to dark mode"><span class="woodcrest-theme-toggle-icon" aria-hidden="true">☾</span><span class="woodcrest-theme-toggle-label">Dark</span></button>
            <div class="woodcrest-login-app-name">
                <span>WOODCREST</span>
                <span class="utility-box">Utility Management Tool</span>
            </div>
            
            <div class="woodcrest-login-toggle-wrapper">
                <button type="button" class="toggle-btn active tenant" data-role="tenant" aria-pressed="true">Tenant</button>
                <button type="button" class="toggle-btn manager" data-role="manager" aria-pressed="false">Property Manager</button>
            </div>
            
            <div class="woodcrest-login-header">
                <h2 id="login-title">Tenant Login</h2>
            </div>

            <form method="post" action="<?php echo esc_url(site_url('wp-login.php')); ?>" id="woodcrest-login-form">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url(home_url('/dashboard-router/')); ?>">
                
                <div class="woodcrest-form-group">
                    <label for="log">EMAIL ADDRESS or USERNAME</label>
                    <input type="text" name="log" id="log" placeholder="Email or Username" required>
                </div>

                <div class="woodcrest-form-group">
                    <label for="pwd">Password</label>
                    <input type="password" name="pwd" id="pwd" placeholder="••••••••" required>
                </div>

                <div class="woodcrest-form-footer">
                    <label class="remember-me">
                        <input name="rememberme" type="checkbox" value="forever"> Remember Me
                    </label>
                    <a href="<?php echo esc_url(wp_lostpassword_url()); ?>" class="forgot-link">Forgot Password?</a>
                </div>

                <button type="submit" class="woodcrest-submit-btn" id="submit-btn">Sign in as Tenant</button>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.body.classList.add('woodcrest-login-page-active');
        const buttons = document.querySelectorAll('.toggle-btn');
        const title = document.getElementById('login-title');
        const submitBtn = document.getElementById('submit-btn');
        const loginContainer = document.querySelector('.woodcrest-login-container');
        function alignLoginToViewport() {
            if (!loginContainer) return;
            loginContainer.style.translate = 'none';
            const rect = loginContainer.getBoundingClientRect();
            const centeredOffset = (document.documentElement.clientWidth / 2) - (rect.left + rect.width / 2);
            if (Math.abs(centeredOffset) > 1) loginContainer.style.translate = centeredOffset + 'px 0';
        }
        window.requestAnimationFrame(alignLoginToViewport);
        window.addEventListener('resize', alignLoginToViewport);

        buttons.forEach(btn => {
            btn.addEventListener('click', function() {
                buttons.forEach(b => { b.classList.remove('active'); b.setAttribute('aria-pressed', 'false'); });
                this.classList.add('active');
                this.setAttribute('aria-pressed', 'true');
                
                const role = this.getAttribute('data-role');
                if (role === 'manager') {
                    title.innerText = 'Property Manager Login';
                    submitBtn.innerText = 'Sign In as Property Manager';
                    submitBtn.className = 'woodcrest-submit-btn manager-btn';
                    loginContainer.setAttribute('data-login-role', 'manager');
                } else {
                    title.innerText = 'Tenant Login';
                    submitBtn.innerText = 'Sign In as Tenant';
                    submitBtn.className = 'woodcrest-submit-btn tenant-btn';
                    loginContainer.setAttribute('data-login-role', 'tenant');
                }
            });
        });
    });
    </script>
    <?php return ob_get_clean();
});

add_shortcode('woodcrest_dashboard_router', function() {
    if (!is_user_logged_in()) { wp_safe_redirect(site_url('wp-login.php')); exit; }
    
    if (current_user_can('manage_options')) {
        wp_safe_redirect(home_url('/admin-user-page/'));
        exit;
    }

    $user = wp_get_current_user();
    global $woodcrest_db;
    $access = $woodcrest_db->get_row($woodcrest_db->prepare("SELECT access_scope FROM {$woodcrest_db->user_property_access} WHERE user_id = %d LIMIT 1", $user->ID));
    if ($access && $access->access_scope === 'PROPERTY_MANAGER') { wp_safe_redirect(home_url('/property-manager-user-page/')); exit; }
    wp_safe_redirect(home_url('/tenant-user-page/')); exit;
});
add_action('template_redirect', function() {
    $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    if ($path === 'dashboard-router') {
        if (!is_user_logged_in()) {
            wp_safe_redirect(site_url('wp-login.php'));
            exit;
        }

        if (current_user_can('manage_options')) {
            wp_safe_redirect(home_url('/admin-user-page/'));
            exit;
        }

        $user = wp_get_current_user();
        global $woodcrest_db;

        
        $access = $woodcrest_db->get_row($woodcrest_db->prepare(
            "SELECT access_scope FROM {$woodcrest_db->user_property_access} WHERE user_id = %d LIMIT 1", 
            $user->ID
        ));

        if ($access && $access->access_scope === 'PROPERTY_MANAGER') {
            wp_safe_redirect(home_url('/property-manager-user-page/'));
        } else {
            wp_safe_redirect(home_url('/tenant-user-page/'));
        }
        exit;
    }
});
