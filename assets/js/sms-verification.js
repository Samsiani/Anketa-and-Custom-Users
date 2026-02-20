/**
 * ACU SMS OTP Verification Script
 * Anketa and Custom Users
 *
 * Works on four locations:
 * 1. Registration Shortcode Form ([club_anketa_form]) — #anketa_phone_local
 * 2. WooCommerce Checkout Page — #billing_phone
 * 3. WooCommerce Registration Form — #reg_billing_phone
 * 4. My Account Edit Details Page — #account_phone
 */
(function ($) {
    'use strict';

    if (typeof acuSms === 'undefined') {
        return;
    }

    var i18n    = acuSms.i18n;
    var ajaxUrl = acuSms.ajaxUrl;
    var nonce   = acuSms.nonce;

    // State
    var verifiedPhone         = acuSms.verifiedPhone || '';
    var sessionVerifiedPhone  = '';
    var verificationToken     = '';
    var countdownInterval     = null;
    var resendCountdown       = 60;
    var currentPhoneField     = null;
    var pendingCheckoutSubmit = false;

    function init() {
        injectModalHtml();
        injectVerifyButtonForWooCommerce();
        bindEvents();
        initializePhoneFields();
        updateSubmitButtonStates();

        $(document.body).on('updated_checkout', function () {
            injectVerifyButtonForWooCommerce();
            initializePhoneFields();
            updateSubmitButtonStates();
        });
    }

    function injectVerifyButtonForWooCommerce() {
        var isCheckoutPage = $('form.checkout').length > 0;
        var phoneSelectors = [
            '#billing_phone',
            '#reg_billing_phone',
            '#account_phone',
            '#anketa_phone_local'
        ];

        phoneSelectors.forEach(function (selector) {
            var $phoneInput = $(selector);
            if ($phoneInput.length === 0 || $phoneInput.closest('.phone-verify-group').length > 0) {
                return;
            }
            if (isCheckoutPage && selector === '#billing_phone') {
                return;
            }

            var $existingContainer = $phoneInput.siblings('.phone-verify-container');
            if ($existingContainer.length === 0) {
                $existingContainer = $phoneInput.parent().siblings('.phone-verify-container');
            }
            if ($existingContainer.length === 0) {
                var $formRow = $phoneInput.closest('.form-row, p');
                $existingContainer = $formRow.siblings('.phone-verify-container');
            }
            if ($existingContainer.length === 0) {
                var $formRow2 = $phoneInput.closest('.form-row, p');
                $existingContainer = $formRow2.nextAll('.phone-verify-container').first();
            }
            if ($existingContainer.length === 0) {
                var $form = $phoneInput.closest('form');
                if ($form.length > 0) {
                    $existingContainer = $form.find('.phone-verify-container').not('.phone-verify-group .phone-verify-container').first();
                }
            }

            if ($existingContainer.length > 0) {
                var $wrapper = $('<div class="phone-verify-group wc-phone-verify-group"></div>');
                $phoneInput.before($wrapper);
                $wrapper.append($phoneInput);
                $wrapper.append($existingContainer.detach());
            } else {
                $phoneInput.wrap('<div class="phone-verify-group wc-phone-verify-group"></div>');
                var verifyHtml =
                    '<div class="phone-verify-container">' +
                    '<button type="button" class="phone-verify-btn" aria-label="' + i18n.verify + '">' +
                    (i18n.verifyBtn || 'Verify') + '</button>' +
                    '<span class="phone-verified-icon" style="display:none;" aria-label="' + i18n.verified + '">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                    '</span></div>';
                $phoneInput.after(verifyHtml);
            }
        });
    }

    function injectModalHtml() {
        $('#acu-otp-modal').remove();

        var modalHtml =
            '<div id="acu-otp-modal" class="club-anketa-modal" style="display:none;">' +
            '<div class="club-anketa-modal-overlay"></div>' +
            '<div class="club-anketa-modal-content">' +
            '<button type="button" class="club-anketa-modal-close">&times;</button>' +
            '<div class="club-anketa-modal-header">' +
            '<h3>' + i18n.modalTitle + '</h3>' +
            '<p class="modal-subtitle">' + i18n.modalSubtitle + ' <span class="otp-phone-display"></span></p>' +
            '</div>' +
            '<div class="club-anketa-modal-body">' +
            '<div class="otp-input-container">' +
            '<input type="text" class="otp-digit" maxlength="1" data-index="0" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code" />' +
            '<input type="text" class="otp-digit" maxlength="1" data-index="1" inputmode="numeric" pattern="[0-9]" />' +
            '<input type="text" class="otp-digit" maxlength="1" data-index="2" inputmode="numeric" pattern="[0-9]" />' +
            '<input type="text" class="otp-digit" maxlength="1" data-index="3" inputmode="numeric" pattern="[0-9]" />' +
            '<input type="text" class="otp-digit" maxlength="1" data-index="4" inputmode="numeric" pattern="[0-9]" />' +
            '<input type="text" class="otp-digit" maxlength="1" data-index="5" inputmode="numeric" pattern="[0-9]" />' +
            '</div>' +
            '<div class="otp-message"></div>' +
            '<div class="otp-resend-container">' +
            '<span class="otp-countdown" style="display:none;">' + i18n.resendIn + ' <span class="countdown-timer">60</span> წამი</span>' +
            '<button type="button" class="otp-resend-btn" style="display:none;">' + i18n.resend + '</button>' +
            '</div>' +
            '</div>' +
            '<div class="club-anketa-modal-footer">' +
            '<button type="button" class="otp-verify-btn">' + i18n.verify + '</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        $(document.body).append(modalHtml);
    }

    function initializePhoneFields() {
        $('.phone-local, #billing_phone, #reg_billing_phone, #account_phone, #anketa_phone_local').each(function () {
            updatePhoneFieldState($(this), $(this).val());
        });
    }

    function normalizePhone(phone) {
        if (!phone) return '';
        var digits = phone.replace(/\D/g, '');
        if (digits.length > 9 && digits.indexOf('995') === 0) {
            digits = digits.substring(3);
        }
        if (digits.length > 9) {
            digits = digits.substring(digits.length - 9);
        }
        return digits;
    }

    function isPhoneVerified(phone) {
        var norm = normalizePhone(phone);
        if (!norm || norm.length !== 9) return false;
        return norm === verifiedPhone || norm === sessionVerifiedPhone;
    }

    function updatePhoneFieldState($input, currentPhone) {
        var $container  = $input.closest('.phone-verify-group, .phone-group');
        var $verifyBtn  = $container.find('.phone-verify-btn');
        var $verifiedIcon = $container.find('.phone-verified-icon');
        if ($verifyBtn.length === 0 && $verifiedIcon.length === 0) return;

        var norm     = normalizePhone(currentPhone);
        var valid    = norm.length === 9;
        var verified = isPhoneVerified(norm);

        if (verified && valid) {
            $verifyBtn.hide(); $verifiedIcon.show();
            $container.addClass('phone-verified').removeClass('phone-unverified');
        } else if (valid) {
            $verifyBtn.show(); $verifiedIcon.hide();
            $container.addClass('phone-unverified').removeClass('phone-verified');
        } else {
            $verifyBtn.hide(); $verifiedIcon.hide();
            $container.removeClass('phone-verified phone-unverified');
        }
        updateSubmitButtonStates();
    }

    function updateSubmitButtonStates() {
        $('.club-anketa-form, form.checkout, form.woocommerce-EditAccountForm, form.edit-address, form.woocommerce-form-register, form.register').each(function () {
            var $form      = $(this);
            var $phoneInput = $form.find('#anketa_phone_local, .phone-local, #billing_phone, #reg_billing_phone, #account_phone').first();
            var $submitBtn  = $form.find('.submit-btn, button[type="submit"], input[type="submit"]').not('.phone-verify-btn, .otp-verify-btn, .otp-resend-btn');
            if ($phoneInput.length === 0 || $submitBtn.length === 0) return;

            var norm     = normalizePhone($phoneInput.val());
            var valid    = norm.length === 9;
            var verified = isPhoneVerified(norm);

            var isAnketa    = $form.hasClass('club-anketa-form');
            var isCheckout  = $form.hasClass('checkout');
            var isAccount   = $form.hasClass('woocommerce-EditAccountForm') || $form.hasClass('edit-address');
            var isWcReg     = $form.hasClass('woocommerce-form-register') || $form.hasClass('register');
            var requiresVerification = !isAnketa && !isCheckout && (isAccount || isWcReg || $form.find('.phone-verify-group').length > 0);

            if (requiresVerification && valid && !verified) {
                $submitBtn.prop('disabled', true).addClass('verification-blocked');
                if (!$form.find('.phone-verify-warning').length) {
                    $submitBtn.before('<p class="phone-verify-warning">' + (i18n.verificationRequired || 'Phone verification required') + '</p>');
                }
            } else {
                $submitBtn.prop('disabled', false).removeClass('verification-blocked');
                $form.find('.phone-verify-warning').remove();
            }
        });
    }

    function bindEvents() {
        $(document).on('input change', '.phone-local, #billing_phone, #reg_billing_phone, #account_phone, #anketa_phone_local', function () {
            currentPhoneField = $(this);
            updatePhoneFieldState($(this), $(this).val());
        });

        $(document).on('click', '.phone-verify-btn', function (e) {
            e.preventDefault(); e.stopPropagation();
            var $btn = $(this);
            var $container = $btn.closest('.phone-verify-group, .phone-group');
            var $input = $container.find('.phone-local, #billing_phone, #reg_billing_phone, #account_phone, #anketa_phone_local, input[type="tel"]').first();
            if ($input.length === 0) {
                var $vc = $btn.closest('.phone-verify-container');
                if ($vc.length > 0) {
                    $input = $vc.siblings('input[type="tel"], #billing_phone, #reg_billing_phone, #account_phone').first();
                    if ($input.length === 0) $input = $vc.prev('input[type="tel"], #billing_phone, #reg_billing_phone, #account_phone');
                }
                if ($input.length === 0) {
                    var $f = $btn.closest('form');
                    if ($f.length > 0) $input = $f.find('#billing_phone, #reg_billing_phone, #account_phone, #anketa_phone_local, .phone-local, input[type="tel"]').first();
                    if ($input.length === 0) $input = $('#billing_phone, #reg_billing_phone, #account_phone, #anketa_phone_local').first();
                }
            }
            var phone = normalizePhone($input.val());
            if (!phone || phone.length !== 9) {
                showModalError(i18n.phoneRequired || 'Phone number must be 9 digits');
                $input.focus(); return;
            }
            currentPhoneField = $input;
            $btn.prop('disabled', true).addClass('loading');
            openModal(phone);
            showMessage(i18n.sendingOtp || 'Sending code...', 'info');
            sendOtp(phone, function () {
                $btn.prop('disabled', false).removeClass('loading');
                showMessage(i18n.enterCode || 'Enter the 6-digit code', 'success');
                startResendCountdown(60);
            }, function (errorMessage) {
                $btn.prop('disabled', false).removeClass('loading');
                showMessage(errorMessage || i18n.error, 'error');
            });
        });

        $(document).on('submit', '.club-anketa-form, form.checkout, form.woocommerce-EditAccountForm, form.edit-address, form.woocommerce-form-register, form.register', function (e) {
            return handleFormSubmit(e, $(this));
        });

        $(document).on('click', '#place_order', function (e) {
            return handleCheckoutPlaceOrder(e);
        });

        $(document.body).on('checkout_error', function () {
            updateSubmitButtonStates();
        });

        $(document).on('click', '.club-anketa-modal-close, .club-anketa-modal-overlay', closeModal);
        $(document).on('input', '.otp-digit', handleOtpInput);
        $(document).on('keydown', '.otp-digit', handleOtpKeydown);
        $(document).on('paste', '.otp-digit', handleOtpPaste);
        $(document).on('click', '.otp-verify-btn', verifyOtp);
        $(document).on('click', '.otp-resend-btn', function () {
            var phone = $('.otp-phone-display').data('phone');
            if (phone) sendOtp(phone);
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') closeModal();
        });
    }

    function handleCheckoutPlaceOrder(e) {
        var $form = $('form.checkout');
        if ($form.length === 0) return true;
        var $phoneInput = $form.find('#billing_phone');
        if ($phoneInput.length === 0) return true;
        var phone = normalizePhone($phoneInput.val());
        if (phone.length !== 9) return true;
        if (isPhoneVerified(phone)) { updateVerificationToken(verificationToken); return true; }

        e.preventDefault(); e.stopImmediatePropagation();
        currentPhoneField     = $phoneInput;
        pendingCheckoutSubmit = true;
        openModal(phone);
        showMessage(i18n.sendingOtp || 'Sending code...', 'info');
        sendOtp(phone, function () {
            showMessage(i18n.enterCode || 'Enter the 6-digit code', 'success');
            startResendCountdown(60);
        }, function (errorMessage) {
            showMessage(errorMessage || i18n.error, 'error');
            pendingCheckoutSubmit = false;
        });
        return false;
    }

    function handleFormSubmit(e, $form) {
        var $phoneInput = $form.find('#anketa_phone_local, .phone-local, #billing_phone, #reg_billing_phone, #account_phone').first();
        if ($phoneInput.length === 0) return true;
        var phone    = normalizePhone($phoneInput.val());
        var isAnketa  = $form.hasClass('club-anketa-form');
        var isCheckout = $form.hasClass('checkout');
        if (isCheckout) { updateVerificationToken(verificationToken); return true; }
        var isAccount = $form.hasClass('woocommerce-EditAccountForm') || $form.hasClass('edit-address');
        var isWcReg   = $form.hasClass('woocommerce-form-register') || $form.hasClass('register');
        var requires  = !isAnketa && (isAccount || isWcReg || $form.find('.phone-verify-group').length > 0);
        if (requires && phone.length === 9 && !isPhoneVerified(phone)) {
            e.preventDefault(); e.stopPropagation();
            var $vBtn = $form.find('.phone-verify-btn');
            if ($vBtn.length > 0 && $vBtn.is(':visible')) {
                $vBtn.addClass('highlight-pulse');
                setTimeout(function () { $vBtn.removeClass('highlight-pulse'); }, 2000);
            }
            showModalError(i18n.verificationRequired || 'Please verify your phone number before submitting.');
            return false;
        }
        updateVerificationToken(verificationToken);
        return true;
    }

    function showModalError(message) {
        if ($('#acu-otp-modal').is(':visible')) showMessage(message, 'error');
        else alert(message);
    }

    function sendOtp(phone, successCallback, errorCallback) {
        $.ajax({
            url: ajaxUrl, type: 'POST',
            data: { action: 'acu_send_otp', nonce: nonce, phone: phone },
            success: function (response) {
                console.log('[ACU SMS] Send OTP response:', response);
                if (response.success) {
                    if (typeof successCallback === 'function') successCallback();
                } else {
                    var msg = response.data && response.data.message ? response.data.message : (i18n.error || 'SMS sending failed');
                    showMessage(msg, 'error');
                    if (typeof errorCallback === 'function') errorCallback(msg);
                }
            },
            error: function (xhr, status, error) {
                console.error('[ACU SMS] AJAX error:', { status: status, error: error });
                var msg = i18n.error || 'Network error. Please try again.';
                showMessage(msg, 'error');
                if (typeof errorCallback === 'function') errorCallback(msg);
            }
        });
    }

    function verifyOtp() {
        var code  = getOtpCode();
        var phone = $('.otp-phone-display').data('phone');
        if (code.length !== 6) { showMessage(i18n.enterCode || 'Please enter the 6-digit code', 'error'); return; }
        var $btn = $('.otp-verify-btn');
        $btn.prop('disabled', true).text(i18n.verifying || 'Verifying...');
        $.ajax({
            url: ajaxUrl, type: 'POST',
            data: { action: 'acu_verify_otp', nonce: nonce, phone: phone, code: code },
            success: function (response) {
                console.log('[ACU SMS] Verify OTP response:', response);
                $btn.prop('disabled', false).text(i18n.verify || 'Verify');
                if (response.success) {
                    sessionVerifiedPhone = response.data.verifiedPhone || phone;
                    verificationToken    = response.data.token;
                    if (response.data.verifiedPhone) verifiedPhone = response.data.verifiedPhone;
                    updateVerificationToken(verificationToken);
                    showMessage(i18n.verified || 'Verified!', 'success');
                    $('.phone-local, #billing_phone, #reg_billing_phone, #account_phone, #anketa_phone_local').each(function () {
                        updatePhoneFieldState($(this), $(this).val());
                    });
                    setTimeout(function () {
                        closeModal();
                        updateSubmitButtonStates();
                        if (pendingCheckoutSubmit) {
                            pendingCheckoutSubmit = false;
                            if ($('form.checkout').length > 0) {
                                setTimeout(function () { $('#place_order').trigger('click'); }, 100);
                            }
                        }
                    }, 800);
                } else {
                    var msg = (response.data && response.data.message) ? response.data.message : (i18n.invalidCode || 'Invalid code');
                    showMessage(msg, 'error');
                    clearOtpInputs();
                }
            },
            error: function (xhr, status, error) {
                console.error('[ACU SMS] Verify AJAX error:', { status: status, error: error });
                $btn.prop('disabled', false).text(i18n.verify || 'Verify');
                showMessage(i18n.error || 'Network error. Please try again.', 'error');
            }
        });
    }

    function updateVerificationToken(token) {
        $('.otp-verification-token').val(token);
        $('form.club-anketa-form, form.checkout, form.woocommerce-EditAccountForm, form.edit-address, form.woocommerce-form-register, form.register').each(function () {
            var $form = $(this);
            if (!$form.find('.otp-verification-token').length) {
                $form.append('<input type="hidden" name="otp_verification_token" value="' + token + '" class="otp-verification-token" />');
            } else {
                $form.find('.otp-verification-token').val(token);
            }
        });
    }

    function getOtpCode() {
        var code = '';
        $('.otp-digit').each(function () { code += $(this).val(); });
        return code;
    }

    function clearOtpInputs() { $('.otp-digit').val('').first().focus(); }

    function handleOtpInput() {
        var $this = $(this);
        var val   = $this.val().replace(/\D/g, '');
        if (val.length > 1) val = val.charAt(0);
        $this.val(val);
        if (val.length === 1) {
            var $next = $('.otp-digit[data-index="' + (parseInt($this.data('index')) + 1) + '"]');
            if ($next.length > 0) $next.focus();
        }
        if (getOtpCode().length === 6) verifyOtp();
    }

    function handleOtpKeydown(e) {
        var $this = $(this);
        var index = parseInt($this.data('index'));
        if (e.key === 'Backspace' && $this.val() === '' && index > 0) {
            $('.otp-digit[data-index="' + (index - 1) + '"]').val('').focus();
        }
        if (e.key === 'ArrowLeft'  && index > 0) $('.otp-digit[data-index="' + (index - 1) + '"]').focus();
        if (e.key === 'ArrowRight' && index < 5) $('.otp-digit[data-index="' + (index + 1) + '"]').focus();
    }

    function handleOtpPaste(e) {
        e.preventDefault();
        var digits = ((e.originalEvent.clipboardData || window.clipboardData).getData('text')).replace(/\D/g, '').substring(0, 6);
        for (var i = 0; i < digits.length; i++) {
            $('.otp-digit[data-index="' + i + '"]').val(digits.charAt(i));
        }
        var $nextEmpty = $('.otp-digit').filter(function () { return !$(this).val(); }).first();
        if ($nextEmpty.length > 0) $nextEmpty.focus(); else $('.otp-digit').last().focus();
        if (digits.length === 6) verifyOtp();
    }

    function openModal(phone) {
        $('.otp-phone-display').text('+995 ' + phone).data('phone', phone);
        clearOtpInputs();
        $('.otp-message').empty().removeClass('success error info');
        $('#acu-otp-modal').fadeIn(200);
        $('.otp-digit').first().focus();
        $('body').addClass('club-anketa-modal-open');
    }

    function closeModal() {
        $('#acu-otp-modal').fadeOut(200);
        $('body').removeClass('club-anketa-modal-open');
        clearCountdown();
    }

    function showMessage(message, type) {
        $('.otp-message').removeClass('success error info').addClass(type).text(message);
    }

    function startResendCountdown(seconds) {
        resendCountdown = seconds || 60;
        $('.otp-resend-btn').hide();
        $('.otp-countdown').show();
        clearCountdown();
        updateCountdownDisplay();
        countdownInterval = setInterval(function () {
            resendCountdown--;
            updateCountdownDisplay();
            if (resendCountdown <= 0) {
                clearCountdown();
                $('.otp-countdown').hide();
                $('.otp-resend-btn').show();
            }
        }, 1000);
    }

    function updateCountdownDisplay() { $('.countdown-timer').text(resendCountdown); }
    function clearCountdown() { if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; } }

    $(document).ready(init);

})(jQuery);
