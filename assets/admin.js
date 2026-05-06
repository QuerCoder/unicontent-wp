jQuery(function ($) {
    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function parseJsonScript(selector, fallback) {
        const $el = $(selector);
        if (!$el.length) {
            return fallback;
        }

        try {
            const parsed = JSON.parse($el.text());
            return parsed || fallback;
        } catch (e) {
            return fallback;
        }
    }

    function insertAtCursor($textarea, text) {
        const el = $textarea.get(0);
        if (!el) {
            return;
        }

        const value = $textarea.val() || '';
        const start = el.selectionStart || 0;
        const end = el.selectionEnd || 0;
        const next = value.substring(0, start) + text + value.substring(end);
        $textarea.val(next);

        const pos = start + text.length;
        el.focus();
        el.setSelectionRange(pos, pos);
    }

    function setInlineStatusBarState($bar, percent, durationMs) {
        if (!$bar || !$bar.length) {
            return;
        }
        const clamped = Math.max(0, Math.min(100, Number(percent || 0)));
        $bar.css({ transition: 'none', width: clamped + '%' });
        if (durationMs > 0) {
            void $bar.get(0).offsetWidth;
            $bar.css({ transition: 'width ' + durationMs + 'ms linear', width: '0%' });
        }
    }

    function setInlineStatusMessage($container, message, isError, ttlMs) {
        if (!$container || !$container.length) {
            return;
        }

        const text = String(message || '').trim();
        if (!text) {
            const prevTimer = Number($container.data('ucgStatusTimer') || 0);
            if (prevTimer) {
                window.clearTimeout(prevTimer);
            }
            $container.removeData('ucgStatusTimer');
            $container.empty();
            return;
        }

        const ttl = Number.isFinite(Number(ttlMs)) ? Math.max(0, Number(ttlMs)) : 5000;
        const cls = isError ? 'ucg-status-message ucg-status-message--error' : 'ucg-status-message ucg-status-message--ok';
        const role = isError ? 'alert' : 'status';
        const progressHidden = ttl <= 0 ? ' hidden' : '';
        const html = '' +
            '<div class="' + cls + '" role="' + role + '">' +
            '<span class="ucg-status-message__text">' + escapeHtml(text) + '</span>' +
            '<span class="ucg-status-message__progress"' + progressHidden + '><span class="ucg-status-message__bar"></span></span>' +
            '</div>';
        $container.html(html);

        const prevTimer = Number($container.data('ucgStatusTimer') || 0);
        if (prevTimer) {
            window.clearTimeout(prevTimer);
        }

        const $message = $container.children('.ucg-status-message').first();
        const $bar = $message.find('.ucg-status-message__bar');

        if (ttl > 0) {
            setInlineStatusBarState($bar, 100, ttl);
            const timeoutId = window.setTimeout(function () {
                if (!$message.length || !$message.closest(document.documentElement).length) {
                    return;
                }
                $message.addClass('is-leaving');
                window.setTimeout(function () {
                    if ($message.closest(document.documentElement).length) {
                        $container.empty();
                    }
                }, 150);
            }, ttl);
            $container.data('ucgStatusTimer', timeoutId);
            return;
        }

        $container.removeData('ucgStatusTimer');
    }

    function initTimedStatusMessages() {
        $('.ucg-status-message').each(function () {
            const $message = $(this);
            if (!$message.length || $message.find('.ucg-status-message__progress').length) {
                return;
            }

            const text = String($message.text() || '').trim();
            if (!text) {
                return;
            }

            $message.html(
                '<span class="ucg-status-message__text">' + escapeHtml(text) + '</span>' +
                '<span class="ucg-status-message__progress"><span class="ucg-status-message__bar"></span></span>'
            );

            const $bar = $message.find('.ucg-status-message__bar');
            setInlineStatusBarState($bar, 100, 5000);

            const timeoutId = window.setTimeout(function () {
                if (!$message.closest(document.documentElement).length) {
                    return;
                }
                $message.addClass('is-leaving');
                window.setTimeout(function () {
                    if ($message.closest(document.documentElement).length) {
                        $message.remove();
                    }
                }, 150);
            }, 5000);
            $message.data('ucgStatusTimer', timeoutId);
        });
    }

    let $globalStatusContainer = $();

    function getGlobalStatusContainer() {
        if ($globalStatusContainer && $globalStatusContainer.length && $globalStatusContainer.closest(document.documentElement).length) {
            return $globalStatusContainer;
        }

        $globalStatusContainer = $('#ucg-global-status-stack');
        if ($globalStatusContainer.length) {
            return $globalStatusContainer;
        }

        $globalStatusContainer = $('<div id="ucg-global-status-stack" aria-live="polite" aria-atomic="false"></div>');
        $('body').append($globalStatusContainer);
        return $globalStatusContainer;
    }

    function setApiStatus(message, isError) {
        const $status = getGlobalStatusContainer();
        if (!$status.length) {
            return;
        }
        setInlineStatusMessage($status, message, !!isError, 5000);
    }

    function jsT(text) {
        const key = String(text == null ? '' : text);
        const map = window.ucgAdmin && ucgAdmin.i18n && typeof ucgAdmin.i18n === 'object'
            ? ucgAdmin.i18n
            : null;
        if (!map || !Object.prototype.hasOwnProperty.call(map, key)) {
            return key;
        }
        return String(map[key]);
    }

    function formatCreditsValue(value, maxDecimals) {
        const num = Number(value || 0);
        if (!Number.isFinite(num)) {
            return '0';
        }
        const decimals = typeof maxDecimals === 'number' ? maxDecimals : 2;
        return num.toLocaleString('ru-RU', {
            minimumFractionDigits: 0,
            maximumFractionDigits: decimals
        }).replace(',', '.');
    }

    function pluralRu(value, forms) {
        const num = Math.abs(Math.trunc(Number(value) || 0));
        const mod10 = num % 10;
        const mod100 = num % 100;
        if (mod10 === 1 && mod100 !== 11) {
            return forms[0];
        }
        if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
            return forms[1];
        }
        return forms[2];
    }

    function setBalanceValue(credits) {
        const $targets = $('.ucg-balance-value');
        if (!$targets.length) {
            return;
        }
        $targets.text(formatCreditsValue(credits, 2));
    }

    function readBalanceSnapshot() {
        if (!window.sessionStorage) {
            return null;
        }

        try {
            const raw = window.sessionStorage.getItem('ucg_balance_snapshot');
            if (!raw) {
                return null;
            }
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object') {
                return null;
            }
            const credits = Number(parsed.credits);
            const ts = Number(parsed.ts);
            if (!Number.isFinite(credits) || !Number.isFinite(ts)) {
                return null;
            }
            return { credits: credits, ts: ts };
        } catch (e) {
            return null;
        }
    }

    function writeBalanceSnapshot(credits) {
        if (!window.sessionStorage) {
            return;
        }

        try {
            window.sessionStorage.setItem('ucg_balance_snapshot', JSON.stringify({
                credits: Number(credits || 0),
                ts: Date.now()
            }));
        } catch (e) {
            // ignore session storage errors
        }
    }

    function setButtonLoading($button, isLoading) {
        if (!$button || !$button.length) {
            return;
        }
        if (isLoading) {
            $button.addClass('is-loading').prop('disabled', true);
            return;
        }
        $button.removeClass('is-loading').prop('disabled', false);
    }

    function setApiKeyUiState(hasKey, maskedKey) {
        const $input = $('#ucg-api-key-input');
        const $saveButton = $('#ucg-save-api-key');
        const $deleteButton = $('#ucg-delete-api-key');
        const $currentKey = $('#ucg-current-key');
        const $dashboardKeyHint = $('#ucg-dashboard-key-hint');
        if (!$input.length) {
            return;
        }

        const exists = !!hasKey;
        const nextMasked = String(maskedKey || '');

        if (exists) {
            $input.prop('readonly', true).attr('aria-readonly', 'true');
            $input.val(nextMasked || jsT('Ключ сохранён'));
            $input.attr('placeholder', jsT('Ключ сохранён'));
            $saveButton.hide();
            $deleteButton.show();
            if ($dashboardKeyHint.length) {
                $dashboardKeyHint.prop('hidden', true);
            }
            if ($currentKey.length) {
                $currentKey.text(nextMasked || jsT('скрыт'));
            }
            return;
        }

        $input.prop('readonly', false).removeAttr('aria-readonly');
        $input.val('');
        $input.attr('placeholder', jsT('Вставьте API ключ'));
        $saveButton.show();
        $deleteButton.hide();
        if ($dashboardKeyHint.length) {
            $dashboardKeyHint.prop('hidden', false);
        }
        if ($currentKey.length) {
            $currentKey.text(jsT('не задан'));
        }
    }

    function fetchBalance(force, $button) {
        if (!force) {
            const snapshot = readBalanceSnapshot();
            if (snapshot && (Date.now() - snapshot.ts) < 90000) {
                setBalanceValue(snapshot.credits);
                return $.Deferred().resolve({ success: true, cached: true }).promise();
            }
        }

        const loadingText = (window.ucgAdmin && window.ucgAdmin.strings && ucgAdmin.strings.loading) ? ucgAdmin.strings.loading : jsT('Обновляем баланс...');
        setApiStatus(loadingText, false);
        setButtonLoading($button, true);

        return $.post(ucgAdmin.ajaxUrl, {
            action: 'ucg_get_balance',
            nonce: ucgAdmin.nonce,
            force: force ? 1 : 0
        }).done(function (response) {
            if (!response.success) {
                const msg = response.data && response.data.message ? response.data.message : jsT('Ошибка загрузки баланса.');
                setApiStatus(msg, true);
                return;
            }

            const credits = response.data && typeof response.data.credits !== 'undefined' ? response.data.credits : 0;
            setBalanceValue(credits);
            writeBalanceSnapshot(credits);
            setApiStatus(jsT('Баланс: ') + formatCreditsValue(credits, 2), false);
        }).fail(function () {
            setApiStatus(jsT('AJAX ошибка при получении баланса.'), true);
        }).always(function () {
            setButtonLoading($button, false);
        });
    }

    function saveApiKey($button) {
        const $input = $('#ucg-api-key-input');
        if (!$input.length) {
            return;
        }

        const apiKey = String($input.val() || '').trim();
        if (!apiKey) {
            setApiStatus(jsT('Введите API ключ.'), true);
            return;
        }

        const loadingText = (window.ucgAdmin && window.ucgAdmin.strings && ucgAdmin.strings.saving) ? ucgAdmin.strings.saving : jsT('Сохраняем API ключ...');
        setApiStatus(loadingText, false);
        setButtonLoading($button, true);

        $.post(ucgAdmin.ajaxUrl, {
            action: 'ucg_save_api_key',
            nonce: ucgAdmin.nonce,
            api_key: apiKey
        }).done(function (response) {
            if (!response.success) {
                const msg = response.data && response.data.message ? response.data.message : jsT('Не удалось сохранить API ключ.');
                setApiStatus(msg, true);
                return;
            }

            const data = response.data || {};
            const msg = data.message || jsT('Ключ сохранен и проверен.');
            const verified = !!data.verified;
            setApiStatus(msg, !verified);

            if (typeof data.credits !== 'undefined') {
                setBalanceValue(data.credits);
                writeBalanceSnapshot(data.credits);
            }

            if (data.masked_key) {
                $('#ucg-current-key').text(String(data.masked_key));
            }

            setApiKeyUiState(true, data.masked_key || '');

            const $chip = $('#ucg-key-chip');
            if ($chip.length) {
                $chip
                    .removeClass('ucg-chip--ok ucg-chip--bad')
                    .addClass(verified ? 'ucg-chip--ok' : 'ucg-chip--bad')
                    .text(verified ? jsT('Проверен') : jsT('Не проверен'));
            }
        }).fail(function () {
            setApiStatus(jsT('AJAX ошибка при сохранении ключа.'), true);
        }).always(function () {
            setButtonLoading($button, false);
        });
    }

    function deleteApiKey($button) {
        const loadingText = jsT('Удаляем API ключ...');
        setApiStatus(loadingText, false);
        setButtonLoading($button, true);

        $.post(ucgAdmin.ajaxUrl, {
            action: 'ucg_delete_api_key',
            nonce: ucgAdmin.nonce
        }).done(function (response) {
            if (!response.success) {
                const msg = response.data && response.data.message ? response.data.message : jsT('Не удалось удалить API ключ.');
                setApiStatus(msg, true);
                return;
            }

            const $chip = $('#ucg-key-chip');
            if ($chip.length) {
                $chip.removeClass('ucg-chip--ok').addClass('ucg-chip--bad').text(jsT('Не проверен'));
            }
            setBalanceValue(0);
            writeBalanceSnapshot(0);
            setApiKeyUiState(false, '');
            setApiStatus(jsT('Ключ удален.'), false);
        }).fail(function () {
            setApiStatus(jsT('AJAX ошибка при удалении ключа.'), true);
        }).always(function () {
            setButtonLoading($button, false);
        });
    }

    function renderTokenButtons($container, tokens, searchQuery) {
        if (!$container.length) {
            return;
        }

        const sourceTokens = Array.isArray(tokens) ? tokens : [];
        if (!sourceTokens.length) {
            $container.html(jsT('<p class="ucg-muted">Переменные не найдены.</p>'));
            return;
        }

        const query = String(searchQuery || '').trim().toLowerCase();
        const groups = {
            main: [],
            taxonomy: [],
            meta: []
        };

        function tokenGroupKey(token) {
            const normalized = String(token || '').toLowerCase();
            if (normalized.indexOf('{tax:') === 0) {
                return 'taxonomy';
            }
            if (normalized.indexOf('{meta:') === 0 || normalized.indexOf('{acf:') === 0) {
                return 'meta';
            }
            return 'main';
        }

        function tokenGroupTitle(groupKey) {
            if (groupKey === 'taxonomy') {
                return jsT('Таксономии');
            }
            if (groupKey === 'meta') {
                return jsT('Meta-поля');
            }
            return jsT('Основные');
        }

        function compactTokenText(token) {
            const raw = String(token || '');
            if (raw.length <= 32) {
                return raw;
            }
            return raw.slice(0, 14) + '…' + raw.slice(-14);
        }

        sourceTokens.forEach(function (item) {
            const token = item && item.token ? String(item.token) : '';
            const label = item && item.label ? String(item.label) : token;
            if (!token) {
                return;
            }

            if (query) {
                const haystack = (token + ' ' + label).toLowerCase();
                if (haystack.indexOf(query) === -1) {
                    return;
                }
            }

            const groupKey = tokenGroupKey(token);
            if (!Object.prototype.hasOwnProperty.call(groups, groupKey)) {
                groups.main.push({ token: token, label: label });
                return;
            }
            groups[groupKey].push({ token: token, label: label });
        });

        const orderedGroups = ['main', 'taxonomy', 'meta'];
        let totalVisible = 0;
        let html = '';

        orderedGroups.forEach(function (groupKey) {
            const items = groups[groupKey];
            if (!Array.isArray(items) || !items.length) {
                return;
            }
            totalVisible += items.length;

            let buttonsHtml = '';
            items.forEach(function (item) {
                const token = String(item.token || '');
                const label = String(item.label || token);
                const displayToken = compactTokenText(token);
                buttonsHtml += '' +
                    '<button type="button" class="ucg-token-btn" draggable="true" data-token="' + escapeHtml(token) + '" title="' + escapeHtml(label) + '" aria-label="' + escapeHtml(label) + '">' +
                    '<span class="ucg-token-btn__text">' + escapeHtml(displayToken) + '</span>' +
                    '</button>';
            });

            html += '' +
                '<details class="ucg-token-group" open>' +
                '  <summary>' + escapeHtml(tokenGroupTitle(groupKey)) + ' <span class="ucg-token-group__count">' + items.length + '</span></summary>' +
                '  <div class="ucg-token-grid">' + buttonsHtml + '</div>' +
                '</details>';
        });

        if (!totalVisible) {
            $container.html(jsT('<p class="ucg-muted">Переменные не найдены.</p>'));
            return;
        }

        $container.html(html);
    }

    function isTruthyAttr(rawValue, defaultValue) {
        if (typeof rawValue === 'undefined' || rawValue === null || String(rawValue).trim() === '') {
            return !!defaultValue;
        }

        const normalized = String(rawValue).trim().toLowerCase();
        if (normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on') {
            return true;
        }
        if (normalized === '0' || normalized === 'false' || normalized === 'no' || normalized === 'off') {
            return false;
        }
        return !!defaultValue;
    }

    function parseSearchFieldsAttr(rawValue, fallbackFields) {
        const fallback = Array.isArray(fallbackFields) ? fallbackFields.slice() : [];
        const source = String(rawValue == null ? '' : rawValue).trim();
        if (!source) {
            return fallback;
        }

        const fields = [];
        const seen = new Set();
        source.split(',').forEach(function (token) {
            const field = String(token || '').trim();
            if (!field || !/^[A-Za-z0-9_.-]+$/.test(field) || seen.has(field)) {
                return;
            }
            seen.add(field);
            fields.push(field);
        });

        return fields.length ? fields : fallback;
    }

    function escapeHtmlAttr(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function setEnhancedSelectValue($select, value) {
        if (!$select || !$select.length) {
            return;
        }

        const normalized = String(value == null ? '' : value);
        $select.val(normalized);

        const el = $select.get(0);
        if (el && el.tomselect) {
            el.tomselect.setValue(normalized, true);
        }

        $select.trigger('change');
    }

    function getEnhancedSelectValue($select) {
        if (!$select || !$select.length) {
            return '';
        }

        const el = $select.get(0);
        if (el && el.tomselect) {
            const value = el.tomselect.getValue();
            return String(value == null ? '' : value);
        }

        return String($select.val() || '');
    }

    function destroyEnhancedSelectInstance($select) {
        if (!$select || !$select.length) {
            return;
        }

        const el = $select.get(0);
        if (el && el.tomselect) {
            el.tomselect.destroy();
        }
    }

    function initEnhancedSelects($scope) {
        const $root = ($scope && $scope.length) ? $scope : $(document);
        const $selects = $root.is('.ucg-enhanced-select') ? $root : $root.find('.ucg-enhanced-select');
        if (!$selects.length) {
            return;
        }

        if (typeof window.TomSelect === 'undefined') {
            return;
        }

        $selects.each(function () {
            const $select = $(this);
            const element = $select.get(0);
            if (!element) {
                return;
            }

            if (element.tomselect) {
                element.tomselect.destroy();
            }

            const searchEnabled = isTruthyAttr($select.attr('data-search-enabled'), false);
            const searchInDropdown = isTruthyAttr($select.attr('data-search-in-dropdown'), false);
            const configuredSearchFields = parseSearchFieldsAttr($select.attr('data-search-fields'), []);
            const searchPlaceholder = String($select.attr('data-search-placeholder') || '').trim();
            const placeholder = String($select.attr('data-placeholder') || '').trim();
            const maxOptionsRaw = Number($select.attr('data-max-options'));
            const maxOptions = Number.isFinite(maxOptionsRaw) && maxOptionsRaw > 0 ? Math.round(maxOptionsRaw) : 200;

            const config = {
                create: false,
                allowEmptyOption: true,
                maxOptions: maxOptions,
                closeAfterSelect: true,
                copyClassesToDropdown: false,
                render: {
                    no_results: function (data, escape) {
                        const input = data && data.input ? String(data.input) : '';
                        if (!input) {
                            return jsT('<div class="no-results">Ничего не найдено</div>');
                        }
                        return jsT('<div class="no-results">Ничего не найдено для: <strong>') + escape(input) + '</strong></div>';
                    }
                }
            };

            if (!searchEnabled) {
                config.searchField = [];
                config.controlInput = null;
                config.plugins = [];
            } else {
                config.searchField = configuredSearchFields.length ? configuredSearchFields : ['text'];
                if (searchInDropdown) {
                    const dropdownSearchPlaceholder = searchPlaceholder || jsT('Поиск...');
                    config.plugins = ['dropdown_input'];
                    config.controlInput = '<input type="text" autocomplete="off" size="1" placeholder="' + escapeHtmlAttr(dropdownSearchPlaceholder) + '">';
                }
            }

            const ajaxEnabled = isTruthyAttr($select.attr('data-ajax-enabled'), false);
            if (ajaxEnabled) {
                const ajaxUrl = String($select.attr('data-ajax-url') || (window.ucgAdmin && ucgAdmin.ajaxUrl ? ucgAdmin.ajaxUrl : '')).trim();
                const ajaxAction = String($select.attr('data-ajax-action') || '').trim();
                const queryParam = String($select.attr('data-ajax-query-param') || 'q').trim();
                const minCharsRaw = Number($select.attr('data-ajax-min-chars'));
                const minChars = Number.isFinite(minCharsRaw) ? Math.max(0, Math.round(minCharsRaw)) : 1;
                const preloadEnabled = isTruthyAttr($select.attr('data-ajax-preload'), false);
                const ajaxLimitRaw = Number($select.attr('data-ajax-limit'));
                const ajaxLimit = Number.isFinite(ajaxLimitRaw) ? Math.max(5, Math.min(100, Math.round(ajaxLimitRaw))) : 25;
                const loadThrottleRaw = Number($select.attr('data-ajax-throttle'));
                const loadThrottle = Number.isFinite(loadThrottleRaw) ? Math.max(0, Math.round(loadThrottleRaw)) : 250;
                const valueField = String($select.attr('data-ajax-value-field') || 'value').trim();
                const labelField = String($select.attr('data-ajax-label-field') || 'label').trim();
                const searchField = String($select.attr('data-ajax-search-field') || labelField).trim();

                if (ajaxUrl && ajaxAction) {
                    config.valueField = valueField;
                    config.labelField = labelField;
                    config.searchField = searchEnabled
                        ? (configuredSearchFields.length ? configuredSearchFields : [searchField])
                        : [];
                    config.preload = preloadEnabled;
                    config.loadThrottle = loadThrottle;
                    config.shouldLoad = function (query) {
                        return preloadEnabled || query.length >= minChars;
                    };
                    config.load = function (query, callback) {
                        const payload = {
                            action: ajaxAction,
                            nonce: window.ucgAdmin && ucgAdmin.nonce ? ucgAdmin.nonce : '',
                            limit: ajaxLimit
                        };
                        payload[queryParam] = query;

                        $.ajax({
                            url: ajaxUrl,
                            type: 'POST',
                            dataType: 'json',
                            data: payload
                        }).done(function (response) {
                            if (!response) {
                                callback();
                                return;
                            }

                            if (response.success && response.data && Array.isArray(response.data.options)) {
                                callback(response.data.options);
                                return;
                            }

                            if (Array.isArray(response.options)) {
                                callback(response.options);
                                return;
                            }

                            callback();
                        }).fail(function () {
                            callback();
                        });
                    };
                }
            }

            if (placeholder) {
                config.placeholder = placeholder;
            }

            config.onChange = function () {
                $select.trigger('change');
            };

            new window.TomSelect(element, config);
        });
    }

    function initTemplatesPage() {
        const $templatePostType = $('#ucg-template-post-type');
        const $templateScenario = $('#ucg-template-scenario');
        const $templateForm = $templateScenario.length ? $templateScenario.closest('form') : $();
        const $tokensContainer = $('#ucg-template-tokens');
        const $templateTokenSearch = $('#ucg-template-token-search');
        const $templateBody = $('#ucg-template-body');
        const $templateSimpleEditor = $('#ucg-template-simple-editor');
        const $templateSimplePrompt = $('#ucg-template-simple-prompt');
        const $templateBlockEditor = $('#ucg-template-block-editor');
        const $templateBlockRows = $('#ucg-template-block-rows');
        const $addPromptBlock = $('#ucg-add-prompt-block');
        const $templateFieldsEditor = $('#ucg-template-fields-editor');
        const $templateFieldRows = $('#ucg-template-field-rows');
        const $addTemplateField = $('#ucg-add-template-field');
        const $templateFieldsJson = $('#ucg-template-fields-json');
        const $templateName = $('input[name="name"]');
        const $readyTypeFilter = $('#ucg-ready-type-filter');
        const $readyCards = $('.ucg-ready-card');
        const $readyEmpty = $('#ucg-ready-empty');
        const $libraryCategory = $('#ucg-library-category');
        const $libraryType = $('#ucg-library-type');
        const $libraryPrompt = $('#ucg-library-prompt');
        const $libraryApply = $('#ucg-library-apply');
        const $libraryMeta = $('#ucg-library-meta');
        const promptLibraryData = parseJsonScript('#ucg-prompt-library-data', {});
        const promptLibrary = {
            categories: Array.isArray(promptLibraryData.categories) ? promptLibraryData.categories : [],
            types: Array.isArray(promptLibraryData.types) ? promptLibraryData.types : [],
            prompts: Array.isArray(promptLibraryData.prompts) ? promptLibraryData.prompts : []
        };
        const templateFieldsData = parseJsonScript('#ucg-template-fields-data', {});
        const templateFieldPresets = templateFieldsData && typeof templateFieldsData === 'object' && templateFieldsData.presets && typeof templateFieldsData.presets === 'object'
            ? templateFieldsData.presets
            : {};
        const templateLengthOptions = Array.isArray(templateFieldsData.length_options) ? templateFieldsData.length_options : [];
        const templateDefaultLengthOptionId = Number(templateFieldsData.default_length_option_id || 0);
        let $activeTemplateInput = $templateBody;
        let templateTokensSource = [];

        if (
            !$tokensContainer.length &&
            !$templateBody.length &&
            !$templateFieldsEditor.length &&
            !$templateBlockRows.length &&
            !$readyTypeFilter.length &&
            !$readyCards.length
        ) {
            return;
        }

        function isFieldEditorScenario(scenario) {
            const normalized = String(scenario || '').trim().toLowerCase();
            return normalized === 'seo_tags' || normalized === 'post_fields' || normalized === 'product_fields';
        }

        function isBlockEditorScenario(scenario) {
            const normalized = String(scenario || '').trim().toLowerCase();
            return normalized === 'field_update';
        }

        function isSimplePromptScenario(scenario) {
            return !isFieldEditorScenario(scenario) && !isBlockEditorScenario(scenario);
        }

        function normalizeTargetFieldByKey(key) {
            const normalized = String(key || '').trim().toLowerCase();
            const map = {
                post_title: 'post:post_title',
                post_content: 'post:post_content',
                post_excerpt: 'post:post_excerpt',
                seo_title: 'seo_field:title',
                seo_description: 'seo_field:description',
                title: 'seo_field:title',
                description: 'seo_field:description',
                post_author: 'post:post_author',
                post_date: 'post:post_date'
            };
            if (Object.prototype.hasOwnProperty.call(map, normalized)) {
                return map[normalized];
            }
            if (normalized.indexOf('post_') === 0) {
                return 'post:' + normalized;
            }
            if (normalized.indexOf('meta_') === 0) {
                return 'meta:' + normalized.substring(5);
            }
            if (normalized.indexOf('tax_') === 0) {
                return 'tax:' + normalized.substring(4);
            }
            return '';
        }

        function sanitizeTemplateFieldKey(value, fallbackIndex) {
            const normalized = String(value || '').trim().toLowerCase().replace(/[^a-z0-9_:-]+/g, '_').replace(/^_+|_+$/g, '');
            if (normalized) {
                return normalized;
            }
            return 'field_' + String(Number(fallbackIndex || 0) + 1);
        }

        function sanitizeTemplateFieldTarget(value, key) {
            const normalized = String(value || '').trim().replace(/[^a-zA-Z0-9:_-]+/g, '');
            if (normalized) {
                return normalized;
            }
            return normalizeTargetFieldByKey(key);
        }

        function fieldPresetRowsForScenario(scenario) {
            const normalizedScenario = String(scenario || '').trim().toLowerCase();
            const preset = templateFieldPresets && Array.isArray(templateFieldPresets[normalizedScenario])
                ? templateFieldPresets[normalizedScenario]
                : [];
            return preset.map(function (row) {
                return row && typeof row === 'object' ? row : {};
            });
        }

        function buildLengthOptionsHtml(selectedId) {
            const selected = Number(selectedId || 0);
            let html = '<option value="0">' + escapeHtml(jsT('По умолчанию')) + '</option>';
            templateLengthOptions.forEach(function (option) {
                if (!option || typeof option !== 'object') {
                    return;
                }
                const optionId = Number(option.id || 0);
                if (!optionId) {
                    return;
                }
                const optionName = String(option.name || ('#' + optionId));
                const credits = Number(option.credits_cost || 0);
                const creditsLabel = credits > 0 ? (' (' + String(credits).replace(/\.0+$/, '') + ' ' + jsT('кр.') + ')') : '';
                const isSelected = selected === optionId ? ' selected' : '';
                html += '<option value="' + optionId + '"' + isSelected + '>' + escapeHtml(optionName + creditsLabel) + '</option>';
            });
            return html;
        }

        function buildTemplateFieldRow(index, row) {
            const safeIndex = Number(index || 0);
            const source = row && typeof row === 'object' ? row : {};
            const fallbackLength = templateDefaultLengthOptionId > 0 ? templateDefaultLengthOptionId : 0;
            const safeKey = sanitizeTemplateFieldKey(source.key || '', safeIndex);
            const safeLabel = String(source.label || '');
            const safePrompt = String(source.prompt || '');
            const safeTarget = sanitizeTemplateFieldTarget(source.target_field || '', safeKey);
            let safeLengthId = Number(source.length_option_id || 0);
            if (safeLengthId <= 0) {
                safeLengthId = fallbackLength;
            }
            const safeMaxChars = Math.max(0, Number(source.max_chars || 0));
            const isEnabled = !Object.prototype.hasOwnProperty.call(source, 'enabled') || !!source.enabled;
            const checked = isEnabled ? ' checked' : '';

            return '' +
                '<div class="ucg-template-field-row ucg-template-block-row" data-index="' + safeIndex + '">' +
                '  <label class="ucg-field">' +
                '    <span>' + escapeHtml(jsT('Название поля')) + '</span>' +
                '    <input type="text" class="ucg-template-field-label" value="' + escapeHtml(safeLabel) + '" placeholder="' + escapeHtml(jsT('Например: Заголовок')) + '">' +
                '  </label>' +
                '  <div class="ucg-grid-3 ucg-template-field-row__meta-grid">' +
                '    <label class="ucg-field">' +
                '      <span>' + escapeHtml(jsT('Длина')) + '</span>' +
                '      <select class="ucg-template-field-length ucg-enhanced-select" data-search-enabled="false" data-placeholder="' + escapeHtml(jsT('По умолчанию')) + '">' +
                buildLengthOptionsHtml(safeLengthId) +
                '      </select>' +
                '    </label>' +
                '    <label class="ucg-field">' +
                '      <span>' + escapeHtml(jsT('Макс. символов (опц.)')) + '</span>' +
                '      <input type="number" min="0" step="1" class="ucg-template-field-max-chars" value="' + safeMaxChars + '" placeholder="0">' +
                '    </label>' +
                '    <label class="ucg-checkbox ucg-template-field-enabled-wrap">' +
                '      <input type="checkbox" class="ucg-template-field-enabled"' + checked + '>' +
                '      <span>' + escapeHtml(jsT('Поле включено')) + '</span>' +
                '    </label>' +
                '  </div>' +
                '  <details class="ucg-template-field-advanced">' +
                '    <summary>' + escapeHtml(jsT('Расширенные настройки')) + '</summary>' +
                '    <div class="ucg-template-field-advanced__grid">' +
                '      <label class="ucg-field">' +
                '        <span>' + escapeHtml(jsT('Ключ поля')) + '</span>' +
                '        <input type="text" class="ucg-template-field-key" value="' + escapeHtml(safeKey) + '" placeholder="' + escapeHtml(jsT('post_title / seo_title / custom')) + '">' +
                '      </label>' +
                '      <label class="ucg-field">' +
                '        <span>' + escapeHtml(jsT('Целевое поле')) + '</span>' +
                '        <input type="text" class="ucg-template-field-target" value="' + escapeHtml(safeTarget) + '" placeholder="' + escapeHtml(jsT('post:post_title / seo_field:title')) + '">' +
                '      </label>' +
                '    </div>' +
                '  </details>' +
                '  <label class="ucg-field">' +
                '    <span>' + escapeHtml(jsT('Промпт поля')) + '</span>' +
                '    <textarea class="ucg-template-field-prompt ucg-template-block-input" rows="6" placeholder="' + escapeHtml(jsT('Текст промпта для поля')) + '">' + escapeHtml(safePrompt) + '</textarea>' +
                '  </label>' +
                '  <div class="ucg-template-block-actions">' +
                '    <button type="button" class="button button-small ucg-remove-template-field">' + escapeHtml(jsT('Удалить поле')) + '</button>' +
                '  </div>' +
                '</div>';
        }

        function nextTemplateFieldIndex() {
            return $templateFieldRows.find('.ucg-template-field-row').length + 1;
        }

        function addTemplateFieldRow(row) {
            if (!$templateFieldRows.length) {
                return;
            }
            const index = nextTemplateFieldIndex();
            $templateFieldRows.append(buildTemplateFieldRow(index, row || {}));
            const $newRow = $templateFieldRows.find('.ucg-template-field-row').last();
            initEnhancedSelects($newRow.find('.ucg-template-field-length'));
        }

        function templateFieldRowsHavePrompts() {
            if (!$templateFieldRows.length) {
                return false;
            }
            let hasPrompts = false;
            $templateFieldRows.find('.ucg-template-field-row').each(function () {
                const prompt = String($(this).find('.ucg-template-field-prompt').val() || '').trim();
                if (prompt !== '') {
                    hasPrompts = true;
                    return false;
                }
                return true;
            });
            return hasPrompts;
        }

        function collectTemplateFieldsPayload() {
            if (!$templateFieldRows.length) {
                return [];
            }
            const payload = [];
            $templateFieldRows.find('.ucg-template-field-row').each(function (rowIndex) {
                const $row = $(this);
                const rawKey = String($row.find('.ucg-template-field-key').val() || '').trim();
                const rawLabel = String($row.find('.ucg-template-field-label').val() || '').trim();
                const rawTarget = String($row.find('.ucg-template-field-target').val() || '').trim();
                const rawPrompt = String($row.find('.ucg-template-field-prompt').val() || '');
                if (!rawKey && !rawLabel && !rawTarget && String(rawPrompt || '').trim() === '') {
                    return;
                }
                const key = sanitizeTemplateFieldKey(rawKey, rowIndex);
                const targetField = sanitizeTemplateFieldTarget(rawTarget, key);
                const lengthOptionId = Math.max(0, Number($row.find('.ucg-template-field-length').val() || 0));
                const maxChars = Math.max(0, Number($row.find('.ucg-template-field-max-chars').val() || 0));
                payload.push({
                    key: key,
                    label: rawLabel || key,
                    type: 'ai',
                    enabled: $row.find('.ucg-template-field-enabled').is(':checked'),
                    length_option_id: lengthOptionId > 0 ? lengthOptionId : 0,
                    max_chars: maxChars > 0 ? maxChars : 0,
                    target_field: targetField || '',
                    prompt: rawPrompt
                });
            });
            return payload;
        }

        function ensureTemplateFieldPresetIfNeeded(force) {
            if (!$templateFieldRows.length || !$templateScenario.length) {
                return;
            }
            const scenario = String($templateScenario.val() || 'field_update');
            if (!isFieldEditorScenario(scenario)) {
                return;
            }

            const hasRows = $templateFieldRows.find('.ucg-template-field-row').length > 0;
            if (!force && hasRows && templateFieldRowsHavePrompts()) {
                return;
            }

            const presetRows = fieldPresetRowsForScenario(scenario);
            if (!hasRows && !presetRows.length) {
                addTemplateFieldRow({ key: 'field_1', label: jsT('Поле'), prompt: '' });
                return;
            }
            if (!force && hasRows && !templateFieldRowsHavePrompts()) {
                $templateFieldRows.empty();
            } else if (force) {
                $templateFieldRows.empty();
            } else if (hasRows) {
                return;
            }

            if (!presetRows.length) {
                addTemplateFieldRow({ key: 'field_1', label: jsT('Поле'), prompt: '' });
                return;
            }
            presetRows.forEach(function (row) {
                addTemplateFieldRow(row);
            });
        }

        function firstBlockPromptValue() {
            if (!$templateBlockRows.length) {
                return '';
            }
            const $first = $templateBlockRows.find('textarea[name="prompt_blocks_prompt[]"]').first();
            return $first.length ? String($first.val() || '') : '';
        }

        function setFirstBlockPromptValue(value) {
            if (!$templateBlockRows.length) {
                return;
            }
            if (!$templateBlockRows.find('.ucg-template-block-row').length) {
                addPromptBlockRow({ key: 'main', label: jsT('Основной промпт'), prompt: '' });
            }
            const $first = $templateBlockRows.find('textarea[name="prompt_blocks_prompt[]"]').first();
            if ($first.length) {
                $first.val(String(value || ''));
            }
        }

        function syncSimplePromptFromBlocksIfEmpty() {
            if (!$templateSimplePrompt.length) {
                return;
            }
            const currentSimple = String($templateSimplePrompt.val() || '').trim();
            if (currentSimple !== '') {
                return;
            }
            const fromBlock = String(firstBlockPromptValue() || '');
            if (fromBlock.trim() !== '') {
                $templateSimplePrompt.val(fromBlock);
            }
        }

        function syncBlockPromptFromSimpleIfEmpty() {
            const simplePrompt = $templateSimplePrompt.length ? String($templateSimplePrompt.val() || '') : '';
            if (simplePrompt.trim() === '') {
                return;
            }
            const blockPrompt = String(firstBlockPromptValue() || '').trim();
            if (blockPrompt !== '') {
                return;
            }
            setFirstBlockPromptValue(simplePrompt);
        }

        function setBlockPromptInputsEnabled(enabled) {
            if (!$templateBlockEditor.length) {
                return;
            }
            const isEnabled = !!enabled;
            $templateBlockEditor
                .find('input[name^="prompt_blocks_"], textarea[name^="prompt_blocks_"]')
                .prop('disabled', !isEnabled);
        }

        function removeSimplePromptHiddenFields() {
            if (!$templateForm.length) {
                return;
            }
            $templateForm.find('.ucg-simple-prompt-hidden').remove();
        }

        function appendSimplePromptHiddenFields() {
            if (!$templateForm.length) {
                return;
            }
            const simplePrompt = $templateSimplePrompt.length ? String($templateSimplePrompt.val() || '') : '';
            const hiddenRows = [
                { name: 'prompt_blocks_key[]', value: 'main' },
                { name: 'prompt_blocks_label[]', value: jsT('Основной промпт') },
                { name: 'prompt_blocks_prompt[]', value: simplePrompt }
            ];
            hiddenRows.forEach(function (item) {
                $('<input>', {
                    type: 'hidden',
                    class: 'ucg-simple-prompt-hidden',
                    name: item.name,
                    value: item.value
                }).appendTo($templateForm);
            });
        }

        function updateTemplateEditorByScenario(forcePreset) {
            if (!$templateScenario.length) {
                return;
            }
            const scenario = String($templateScenario.val() || 'field_update');
            const useFieldEditor = isFieldEditorScenario(scenario);
            const useBlockEditor = isBlockEditorScenario(scenario);
            const useSimpleEditor = isSimplePromptScenario(scenario);
            if ($templateFieldsEditor.length) {
                $templateFieldsEditor.toggle(useFieldEditor);
            }
            if ($templateBlockEditor.length) {
                $templateBlockEditor.toggle(useBlockEditor);
            }
            if ($templateSimpleEditor.length) {
                $templateSimpleEditor.toggle(useSimpleEditor);
            }

            if (useSimpleEditor) {
                syncSimplePromptFromBlocksIfEmpty();
            } else if (useBlockEditor) {
                syncBlockPromptFromSimpleIfEmpty();
            }

            setBlockPromptInputsEnabled(useBlockEditor);

            if (useFieldEditor) {
                ensureTemplateFieldPresetIfNeeded(!!forcePreset);
                if ($templateFieldsJson.length) {
                    $templateFieldsJson.val(JSON.stringify(collectTemplateFieldsPayload()));
                }
            } else if ($templateFieldsJson.length) {
                $templateFieldsJson.val('[]');
            }
        }

        function firstVisibleTemplatePromptInput() {
            if ($templateFieldsEditor.length && $templateFieldsEditor.is(':visible')) {
                const $fieldPrompt = $templateFieldRows.find('.ucg-template-field-prompt').first();
                if ($fieldPrompt.length) {
                    return $fieldPrompt;
                }
            }
            if ($templateSimpleEditor.length && $templateSimpleEditor.is(':visible') && $templateSimplePrompt.length) {
                return $templateSimplePrompt;
            }
            if ($templateBlockEditor.length && $templateBlockEditor.is(':visible')) {
                const $firstBlockInput = $templateBlockRows.find('.ucg-template-block-input').first();
                if ($firstBlockInput.length) {
                    return $firstBlockInput;
                }
            }
            return $templateBody;
        }

        function activeTemplateEditorInput() {
            if ($activeTemplateInput && $activeTemplateInput.length && $activeTemplateInput.is(':visible')) {
                return $activeTemplateInput;
            }
            return firstVisibleTemplatePromptInput();
        }

        function buildBlockRow(index, key, label, prompt) {
            const safeIndex = Number(index || 0);
            const safeKey = String(key || '');
            const safeLabel = String(label || '');
            const safePrompt = String(prompt || '');
            return '' +
                '<div class="ucg-template-block-row" data-index="' + safeIndex + '">' +
                '  <div class="ucg-grid-2">' +
                '    <label class="ucg-field">' +
                '      <span>' + escapeHtml(jsT('Ключ блока')) + '</span>' +
                '      <input type="text" name="prompt_blocks_key[]" value="' + escapeHtml(safeKey) + '" placeholder="' + escapeHtml(jsT('main / seo_title / excerpt')) + '">' +
                '    </label>' +
                '    <label class="ucg-field">' +
                '      <span>' + escapeHtml(jsT('Название блока')) + '</span>' +
                '      <input type="text" name="prompt_blocks_label[]" value="' + escapeHtml(safeLabel) + '" placeholder="' + escapeHtml(jsT('Например: SEO title')) + '">' +
                '    </label>' +
                '  </div>' +
                '  <label class="ucg-field">' +
                '    <span>' + escapeHtml(jsT('Промпт блока')) + '</span>' +
                '    <textarea name="prompt_blocks_prompt[]" class="ucg-template-block-input" rows="6" placeholder="' + escapeHtml(jsT('Текст промпта для этого блока')) + '">' + escapeHtml(safePrompt) + '</textarea>' +
                '  </label>' +
                '  <div class="ucg-template-block-actions">' +
                '    <button type="button" class="button button-small ucg-remove-prompt-block">' + escapeHtml(jsT('Удалить блок')) + '</button>' +
                '  </div>' +
                '</div>';
        }

        function nextBlockIndex() {
            const rowsCount = $templateBlockRows.find('.ucg-template-block-row').length;
            return rowsCount + 1;
        }

        function addPromptBlockRow(data) {
            if (!$templateBlockRows.length) {
                return;
            }
            const payload = data && typeof data === 'object' ? data : {};
            const index = nextBlockIndex();
            const key = payload.key ? String(payload.key) : ('block_' + index);
            const label = payload.label ? String(payload.label) : '';
            const prompt = payload.prompt ? String(payload.prompt) : '';
            $templateBlockRows.append(buildBlockRow(index, key, label, prompt));
        }

        function applyScenarioPresetIfNeeded() {
            if (!$templateBlockRows.length) {
                return;
            }

            const scenario = String($templateScenario.val() || 'field_update');
            if (!isBlockEditorScenario(scenario)) {
                return;
            }
            const $rows = $templateBlockRows.find('.ucg-template-block-row');
            if (!$rows.length) {
                addPromptBlockRow({ key: 'main', label: jsT('Основной промпт') });
                return;
            }

            if ($rows.length !== 1) {
                return;
            }

            const $singleRow = $rows.first();
            const currentKey = String($singleRow.find('input[name="prompt_blocks_key[]"]').val() || '').trim();
            const currentPrompt = String($singleRow.find('textarea[name="prompt_blocks_prompt[]"]').val() || '').trim();
            if (currentPrompt !== '') {
                return;
            }

            if (currentKey === '' || currentKey === 'seo_title') {
                $templateBlockRows.empty();
                addPromptBlockRow({ key: 'main', label: jsT('Основной промпт') });
            }
        }

        function applyReadyTypeFilter() {
            if (!$readyCards.length) {
                return;
            }

            const selectedType = String($readyTypeFilter.val() || '').trim().toLowerCase();
            let visibleCount = 0;

            $readyCards.each(function () {
                const $card = $(this);
                const cardType = String($card.attr('data-ready-type') || '').trim().toLowerCase();
                const isVisible = !selectedType || cardType === selectedType;
                $card.css('display', isVisible ? '' : 'none');
                if (isVisible) {
                    visibleCount += 1;
                }
            });

            if ($readyEmpty.length) {
                $readyEmpty.prop('hidden', visibleCount > 0);
            }
        }

        function loadTokens(postType) {
            if (!postType) {
                return;
            }

            $.post(ucgAdmin.ajaxUrl, {
                action: 'ucg_get_tokens',
                nonce: ucgAdmin.nonce,
                post_type: postType
            }).done(function (response) {
                if (!response.success) {
                    return;
                }
                const data = response.data || {};
                templateTokensSource = Array.isArray(data.tokens) ? data.tokens : [];
                renderTemplateTokens();
            });
        }

        function collectTemplateTokensFromDom() {
            if (!$tokensContainer.length) {
                return [];
            }
            const result = [];
            $tokensContainer.find('.ucg-token-btn').each(function () {
                const $btn = $(this);
                const token = String($btn.attr('data-token') || '').trim();
                if (!token) {
                    return;
                }
                const label = String($btn.attr('title') || token);
                result.push({ token: token, label: label });
            });
            return result;
        }

        function renderTemplateTokens() {
            if (!$tokensContainer.length) {
                return;
            }
            const query = $templateTokenSearch.length ? String($templateTokenSearch.val() || '') : '';
            renderTokenButtons($tokensContainer, templateTokensSource, query);
        }

        function filteredLibraryPrompts() {
            if (!Array.isArray(promptLibrary.prompts) || !promptLibrary.prompts.length) {
                return [];
            }

            const selectedCategory = String($libraryCategory.val() || '').trim();
            const selectedType = String($libraryType.val() || '').trim();
            return promptLibrary.prompts.filter(function (prompt) {
                const categorySlug = prompt && prompt.category && prompt.category.slug ? String(prompt.category.slug) : '';
                const typeSlug = prompt && prompt.type && prompt.type.slug ? String(prompt.type.slug) : '';
                if (selectedCategory && selectedCategory !== categorySlug) {
                    return false;
                }
                if (selectedType && selectedType !== typeSlug) {
                    return false;
                }
                return true;
            });
        }

        function findLibraryPromptById(promptId) {
            const normalizedId = Number(promptId || 0);
            if (!normalizedId || !Array.isArray(promptLibrary.prompts)) {
                return null;
            }

            for (let i = 0; i < promptLibrary.prompts.length; i += 1) {
                const item = promptLibrary.prompts[i];
                const id = Number(item && item.id ? item.id : 0);
                if (id === normalizedId) {
                    return item;
                }
            }
            return null;
        }

        function renderLibraryMeta(prompt) {
            if (!$libraryMeta.length) {
                return;
            }

            if (!prompt) {
                $libraryMeta.text('');
                return;
            }

            const categoryName = prompt && prompt.category && prompt.category.name ? String(prompt.category.name) : '';
            const typeName = prompt && prompt.type && prompt.type.name ? String(prompt.type.name) : '';
            const summary = prompt && prompt.summary ? String(prompt.summary) : '';
            let meta = [categoryName, typeName].filter(Boolean).join(jsT(' · '));
            if (summary) {
                meta = meta ? (meta + jsT(' · ') + summary) : summary;
            }
            $libraryMeta.text(meta);
        }

        function renderLibraryPromptSelect() {
            if (!$libraryPrompt.length) {
                return;
            }

            const prompts = filteredLibraryPrompts();
            let html = jsT('<option value="">Выберите промпт</option>');

            prompts.forEach(function (prompt) {
                const id = Number(prompt && prompt.id ? prompt.id : 0);
                if (!id) {
                    return;
                }
                const name = prompt && prompt.name ? String(prompt.name) : ('#' + id);
                const categoryName = prompt && prompt.category && prompt.category.name ? String(prompt.category.name) : '';
                const typeName = prompt && prompt.type && prompt.type.name ? String(prompt.type.name) : '';
                let suffix = '';
                if (categoryName || typeName) {
                    suffix = ' (' + [categoryName, typeName].filter(Boolean).join(' / ') + ')';
                }
                html += '<option value="' + id + '">' + escapeHtml(name + suffix) + '</option>';
            });

            $libraryPrompt.html(html);
            setEnhancedSelectValue($libraryPrompt, '');

            const selectedPrompt = findLibraryPromptById($libraryPrompt.val());
            renderLibraryMeta(selectedPrompt);
        }

        function applyLibraryPrompt() {
            const selectedPromptId = Number($libraryPrompt.val() || 0);
            if (!selectedPromptId) {
                renderLibraryMeta(null);
                return;
            }

            const prompt = findLibraryPromptById(selectedPromptId);
            if (!prompt) {
                return;
            }

            const body = prompt && prompt.body ? String(prompt.body) : '';
            if (!body) {
                return;
            }

            const $targetInput = firstVisibleTemplatePromptInput();
            if ($targetInput.length) {
                $targetInput.val(body).trigger('input');
                $activeTemplateInput = $targetInput;
            }
            if ($templateName.length) {
                const currentName = String($templateName.val() || '').trim();
                if (!currentName) {
                    $templateName.val(String(prompt.name || ''));
                }
            }

            renderLibraryMeta(prompt);
        }

        if ($templatePostType.length) {
            $templatePostType.on('change', function () {
                loadTokens(String($(this).val() || ''));
            });
        }

        if ($templateTokenSearch.length) {
            $templateTokenSearch.on('input', function () {
                renderTemplateTokens();
            });
        }

        if ($templateScenario.length) {
            $templateScenario.on('change', function () {
                updateTemplateEditorByScenario(true);
                applyScenarioPresetIfNeeded();
            });
        }

        if ($libraryCategory.length) {
            $libraryCategory.on('change', function () {
                renderLibraryPromptSelect();
            });
        }

        if ($libraryType.length) {
            $libraryType.on('change', function () {
                renderLibraryPromptSelect();
            });
        }

        if ($libraryPrompt.length) {
            $libraryPrompt.on('change', function () {
                const selectedPrompt = findLibraryPromptById($(this).val());
                renderLibraryMeta(selectedPrompt);
            });
        }

        if ($libraryApply.length) {
            $libraryApply.on('click', function () {
                applyLibraryPrompt();
            });
        }

        if ($readyTypeFilter.length) {
            $readyTypeFilter.on('change', function () {
                applyReadyTypeFilter();
            });
        }

        if ($addPromptBlock.length) {
            $addPromptBlock.on('click', function () {
                addPromptBlockRow({ key: 'block_' + nextBlockIndex(), label: '' });
            });
        }

        if ($addTemplateField.length) {
            $addTemplateField.on('click', function () {
                addTemplateFieldRow({
                    key: 'field_' + nextTemplateFieldIndex(),
                    label: '',
                    prompt: '',
                    enabled: true,
                    length_option_id: templateDefaultLengthOptionId > 0 ? templateDefaultLengthOptionId : 0
                });
            });
        }

        $(document).on('click', '.ucg-remove-prompt-block', function () {
            const $row = $(this).closest('.ucg-template-block-row');
            if ($row.length) {
                $row.remove();
            }
            applyScenarioPresetIfNeeded();
        });

        $(document).on('click', '.ucg-remove-template-field', function () {
            const $row = $(this).closest('.ucg-template-field-row');
            if ($row.length) {
                $row.remove();
            }
            const scenario = String($templateScenario.val() || '');
            if (isFieldEditorScenario(scenario) && !$templateFieldRows.find('.ucg-template-field-row').length) {
                ensureTemplateFieldPresetIfNeeded(false);
            }
            if ($templateFieldsJson.length) {
                $templateFieldsJson.val(JSON.stringify(collectTemplateFieldsPayload()));
            }
        });

        $(document).on('focus', '#ucg-template-body, #ucg-template-simple-prompt, .ucg-template-block-input, .ucg-template-field-prompt', function () {
            $activeTemplateInput = $(this);
        });

        $(document).on('click', '#ucg-template-tokens .ucg-token-btn', function () {
            const token = $(this).data('token');
            if (!token) {
                return;
            }
            insertAtCursor(activeTemplateEditorInput(), String(token));
        });

        $(document).on('dragstart', '#ucg-template-tokens .ucg-token-btn', function (event) {
            const token = String($(this).data('token') || '');
            if (!token) {
                return;
            }
            if (event.originalEvent && event.originalEvent.dataTransfer) {
                event.originalEvent.dataTransfer.setData('text/plain', token);
            }
        });

        $(document).on('dragover', '#ucg-template-body, #ucg-template-simple-prompt, .ucg-template-block-input, .ucg-template-field-prompt', function (event) {
            event.preventDefault();
        });

        $(document).on('drop', '#ucg-template-body, #ucg-template-simple-prompt, .ucg-template-block-input, .ucg-template-field-prompt', function (event) {
            event.preventDefault();
            if (!event.originalEvent || !event.originalEvent.dataTransfer) {
                return;
            }
            const token = event.originalEvent.dataTransfer.getData('text/plain');
            if (!token) {
                return;
            }
            const $target = $(this);
            if ($target.length) {
                $activeTemplateInput = $target;
            }
            insertAtCursor(activeTemplateEditorInput(), token);
        });

        if ($templateForm.length) {
            $templateForm.on('submit', function () {
                const scenario = String($templateScenario.val() || 'field_update');
                removeSimplePromptHiddenFields();

                if (!$templateFieldsJson.length) {
                    if (isSimplePromptScenario(scenario)) {
                        appendSimplePromptHiddenFields();
                    }
                    return;
                }

                if (isFieldEditorScenario(scenario)) {
                    $templateFieldsJson.val(JSON.stringify(collectTemplateFieldsPayload()));
                    return;
                }
                $templateFieldsJson.val('[]');
                if (isSimplePromptScenario(scenario)) {
                    appendSimplePromptHiddenFields();
                }
            });
        }

        initEnhancedSelects($templatePostType);
        initEnhancedSelects($templateScenario);
        initEnhancedSelects($readyTypeFilter);
        initEnhancedSelects($libraryCategory);
        initEnhancedSelects($libraryType);
        initEnhancedSelects($libraryPrompt);
        initEnhancedSelects($templateFieldRows.find('.ucg-template-field-length'));

        const readyTypeElement = $readyTypeFilter.length ? $readyTypeFilter.get(0) : null;
        if (readyTypeElement && readyTypeElement.tomselect) {
            readyTypeElement.tomselect.on('change', applyReadyTypeFilter);
        }

        applyReadyTypeFilter();
        renderLibraryPromptSelect();
        applyScenarioPresetIfNeeded();
        updateTemplateEditorByScenario(false);
        templateTokensSource = collectTemplateTokensFromDom();
        renderTemplateTokens();
        if ($templatePostType.length) {
            loadTokens(String($templatePostType.val() || ''));
        }
        const $initialPromptInput = firstVisibleTemplatePromptInput();
        if ($initialPromptInput.length) {
            $activeTemplateInput = $initialPromptInput;
        }
        if ($templateFieldsJson.length) {
            const scenario = String($templateScenario.val() || 'field_update');
            if (isFieldEditorScenario(scenario)) {
                $templateFieldsJson.val(JSON.stringify(collectTemplateFieldsPayload()));
            } else {
                $templateFieldsJson.val('[]');
            }
        }
    }

    function initSettingsPage() {
        const $batchInput = $('#ucg-batch-size-input');
        const $saveBatchButton = $('#ucg-save-batch-size');
        const $generationMode = $('#ucg-generation-mode');
        const $saveStyleDefaults = $('#ucg-save-style-defaults');
        const $defaultLanguage = $('#ucg-default-language');
        const $defaultTone = $('#ucg-default-tone');
        if (!$batchInput.length || !$saveBatchButton.length) {
            return;
        }

        initEnhancedSelects($generationMode);
        initEnhancedSelects($defaultLanguage);
        initEnhancedSelects($defaultTone);

        $saveBatchButton.on('click', function () {
            const raw = Number($batchInput.val() || 20);
            const batchSize = Math.max(1, Math.min(100, Math.round(raw)));
            $batchInput.val(batchSize);
            const generationMode = String($generationMode.val() || 'review');

            const loadingText = (window.ucgAdmin && window.ucgAdmin.strings && ucgAdmin.strings.saving_batch) ? ucgAdmin.strings.saving_batch : jsT('Сохраняем настройки...');
            setApiStatus(loadingText, false);
            setButtonLoading($saveBatchButton, true);

            $.post(ucgAdmin.ajaxUrl, {
                action: 'ucg_save_batch_size',
                nonce: ucgAdmin.nonce,
                batch_size: batchSize,
                generation_mode: generationMode
            }).done(function (response) {
                if (!response.success) {
                    const msg = response.data && response.data.message ? response.data.message : jsT('Не удалось сохранить размер шага.');
                    setApiStatus(msg, true);
                    return;
                }

                const data = response.data || {};
                const saved = Number(data.batch_size || batchSize);
                $batchInput.val(saved);
                if ($generationMode.length && data.generation_mode) {
                    setEnhancedSelectValue($generationMode, String(data.generation_mode));
                }
                setApiStatus(data.message || (jsT('Сохранено: ') + saved), false);
            }).fail(function () {
                setApiStatus(jsT('AJAX ошибка при сохранении настроек.'), true);
            }).always(function () {
                setButtonLoading($saveBatchButton, false);
            });
        });

        if ($saveStyleDefaults.length) {
            $saveStyleDefaults.on('click', function () {
                const loadingText = (window.ucgAdmin && window.ucgAdmin.strings && ucgAdmin.strings.saving_batch)
                    ? ucgAdmin.strings.saving_batch
                    : jsT('Сохраняем настройки...');
                setApiStatus(loadingText, false);
                setButtonLoading($saveStyleDefaults, true);

                $.post(ucgAdmin.ajaxUrl, {
                    action: 'ucg_save_style_defaults',
                    nonce: ucgAdmin.nonce,
                    default_language: String($defaultLanguage.val() || 'auto'),
                    default_tone: String($defaultTone.val() || 'neutral')
                }).done(function (response) {
                    if (!response.success) {
                        const msg = response.data && response.data.message ? response.data.message : jsT('Не удалось сохранить настройки.');
                        setApiStatus(msg, true);
                        return;
                    }
                    setApiStatus(response.data && response.data.message ? response.data.message : jsT('Сохранено.'), false);
                }).fail(function () {
                    setApiStatus(jsT('AJAX ошибка при сохранении настроек.'), true);
                }).always(function () {
                    setButtonLoading($saveStyleDefaults, false);
                });
            });
        }
    }

    function initReviewModal() {
        const $modal = $('#ucg-review-modal');
        if (!$modal.length) {
            return;
        }

        const $generatedContent = $('#ucg-review-generated-content');
        const $currentContent = $('#ucg-review-current-content');

        function setReviewTab(tab) {
            const nextTab = tab === 'current' ? 'current' : 'generated';

            $('.ucg-review-tab').removeClass('is-active');
            $('.ucg-review-tab[data-review-tab="' + nextTab + '"]').addClass('is-active');

            $('.ucg-review-pane').removeClass('is-active');
            $('.ucg-review-pane[data-review-pane="' + nextTab + '"]').addClass('is-active');
        }

        function closeReviewModal() {
            $modal.prop('hidden', true).removeClass('is-open');
            $('body').removeClass('ucg-review-modal-open');
        }

        function openReviewModal(sourceId) {
            const id = String(sourceId || '');
            if (!id) {
                return;
            }

            const $source = $('#' + id);
            if (!$source.length) {
                return;
            }

            const generatedText = $source.find('.ucg-review-source-generated').text();
            const currentText = $source.find('.ucg-review-source-current').text();

            $generatedContent.text(generatedText);
            $currentContent.text(currentText);
            setReviewTab('generated');

            $modal.prop('hidden', false).addClass('is-open');
            $('body').addClass('ucg-review-modal-open');
        }

        $(document).on('click', '.ucg-review-view-btn', function () {
            openReviewModal($(this).data('view-id'));
        });

        $(document).on('click', '[data-close-review-modal]', function () {
            closeReviewModal();
        });

        $(document).on('click', '.ucg-review-tab', function () {
            setReviewTab($(this).data('review-tab'));
        });

        $(document).on('keydown', function (event) {
            if (event.key === 'Escape' && $modal.hasClass('is-open')) {
                closeReviewModal();
            }
        });
    }

    function initLogsPage() {
        const $logs = $('#ucg-logs-json');
        if (!$logs.length) {
            return;
        }

        function download(filename, text) {
            const blob = new Blob([text], { type: 'application/json;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        }

        function copyText(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(text);
            }
            const tmp = document.createElement('textarea');
            tmp.value = text;
            tmp.setAttribute('readonly', 'readonly');
            tmp.style.position = 'absolute';
            tmp.style.left = '-9999px';
            document.body.appendChild(tmp);
            tmp.select();
            document.execCommand('copy');
            tmp.remove();
            return Promise.resolve();
        }

        $('#ucg-copy-logs').on('click', function () {
            const text = String($logs.val() || '');
            copyText(text);
        });

        $('#ucg-download-logs').on('click', function () {
            const text = String($logs.val() || '');
            const dt = new Date();
            const y = dt.getFullYear();
            const m = String(dt.getMonth() + 1).padStart(2, '0');
            const d = String(dt.getDate()).padStart(2, '0');
            download('unicontent-logs-' + y + m + d + '.json', text);
        });

        $('#ucg-copy-diagnostics').on('click', function () {
            try {
                const parsed = JSON.parse(String($logs.val() || '{}'));
                const diag = parsed && parsed.diagnostics ? JSON.stringify(parsed.diagnostics, null, 2) : '';
                copyText(diag || '');
            } catch (e) {
                copyText('');
            }
        });
    }

    function initGenerateWizard() {
        const $wizard = $('#ucg-wizard');
        if (!$wizard.length) {
            return;
        }

        const initialSchema = parseJsonScript('#ucg-wizard-initial', {});
        if (!initialSchema || typeof initialSchema !== 'object') {
            return;
        }
        const initialPrefill = parseJsonScript('#ucg-wizard-prefill', {});

        const state = {
            step: 1,
            schema: initialSchema,
            scenario: (initialSchema && initialSchema.scenario) ? String(initialSchema.scenario) : 'field_update',
            defaultModel: (initialSchema && initialSchema.default_model) ? String(initialSchema.default_model) : 'auto',
            page: 1,
            perPage: 20,
            totalPages: 1,
            total: 0,
            selectedIds: new Set(),
            currentItems: [],
            runMonitorTimer: null,
            activeRunId: 0,
            templateAiFields: [],
            templateStaticFields: [],
            prefill: (initialPrefill && typeof initialPrefill === 'object') ? initialPrefill : {},
            prefillApplied: false,
            quoteEstimate: null,
            quoteEstimateSignature: '',
            quoteError: '',
            quoteRequestSeq: 0,
            quoteRequestTimer: null,
            quoteRequestXhr: null,
            quoteRequestInFlight: false,
            quoteRequestSignature: '',
            quoteFailedSignature: '',
            quoteFailedAt: 0
        };

        const $scenarioCards = $('#ucg-wizard-scenario-picker');
        const $scenarioInputs = $('input[name="ucg-wizard-scenario"]');
        const $postType = $('#ucg-wizard-post-type');
        const $postTypeWrap = $('#ucg-wizard-post-type-wrap');
        const $targetField = $('#ucg-wizard-target-field');
        const $targetFieldWrap = $('#ucg-wizard-target-field-wrap');
        const $targetFieldLabel = $('#ucg-wizard-target-field-label');
        const $itemsPerPostWrap = $('#ucg-wizard-items-per-post-wrap');
        const $itemsPerPost = $('#ucg-wizard-items-per-post');
        const $templateSelect = $('#ucg-wizard-template');
        const $templateName = $('#ucg-wizard-template-name');
        const $templateNameWrap = $('#ucg-template-name-wrap');
        const $saveTemplateLabel = $('#ucg-save-template-label');
        const $lengthOption = $('#ucg-wizard-length-option');
        const $lengthControlsWrap = $('#ucg-length-controls-wrap');
        const $seoGuidelines = $('#ucg-seo-guidelines');
        const $modelSelect = $('#ucg-wizard-model');
        const $varyLength = $('#ucg-wizard-vary-length');
        const $varyLengthHint = $('#ucg-wizard-vary-length-hint');
        const $varyLengthHelp = $('#ucg-wizard-vary-length-help');
        const $templatePromptCard = $('#ucg-template-prompt-card');
        const $templateTokenCard = $('#ucg-template-token-card');
        const $templateBodyStandardWrap = $('#ucg-template-body-standard-wrap');
        const $templateBodySeoWrap = $('#ucg-template-body-seo-wrap');
        const $templateBodyMultiWrap = $('#ucg-template-body-multi-wrap');
        const $templateBody = $('#ucg-wizard-template-body');
        const $templateBodySeoTitle = $('#ucg-wizard-template-body-seo-title');
        const $templateBodySeoDescription = $('#ucg-wizard-template-body-seo-description');
        const $aiFieldRows = $('#ucg-ai-field-rows');
        const $seoFieldSection = $('#ucg-seo-field-section');
        const $seoFieldRows = $('#ucg-seo-field-rows');
        const $staticFieldRows = $('#ucg-static-field-rows');
        const $aiFieldEnabledCount = $('#ucg-ai-field-enabled-count');
        const $seoFieldEnabledCount = $('#ucg-seo-field-enabled-count');
        const $staticFieldEnabledCount = $('#ucg-static-field-enabled-count');
        const $publishDateRangeWrap = $('#ucg-publish-date-range-wrap');
        const $publishDateFrom = $('#ucg-wizard-publish-date-from');
        const $publishDateTo = $('#ucg-wizard-publish-date-to');
        const $wooRatingRangeWrap = $('#ucg-woo-rating-range-wrap');
        const $wooRatingMin = $('#ucg-woo-rating-min');
        const $wooRatingMax = $('#ucg-woo-rating-max');
        const $styleLanguage = $('#ucg-wizard-language');
        const $styleTone = $('#ucg-wizard-tone');
        const $wizardTokenSearch = $('#ucg-wizard-token-search');
        const $tokens = $('#ucg-wizard-tokens');
        const $filterRows = $('#ucg-filter-rows');
        const $previewBody = $('#ucg-preview-tbody');
        const $previewSummary = $('#ucg-preview-summary');
        const $previewFoundCount = $('#ucg-preview-found-count');
        const $previewSelectedCount = $('#ucg-preview-selected-count');
        const $selectionModeFilteredTotal = $('#ucg-selection-mode-filtered-total');
        const $runTargetModeWrap = $('#ucg-run-mode-wrap');
        const $runTargetMode = $('input[name="ucg-run-target-mode"]');
        const $step2CreateWrap = $('#ucg-step2-create-wrap');
        const $step2UpdateWrap = $('#ucg-step2-update-wrap');
        const $createTopics = $('#ucg-create-topics');
        const $previewPagination = $('#ucg-preview-pagination');
        const $selectedCount = $('#ucg-selected-count');
        const $runResult = $('#ucg-run-result');
        const $runSummary = $('#ucg-run-summary');
        const $step3Total = $('#ucg-step-3-total');
        const $toastStack = $('#ucg-toast-stack');
        const $exampleWrap = $('#ucg-example-wrap');
        const $exampleOutput = $('#ucg-example-output');
        const $exampleCredits = $('#ucg-example-credits');
        const $runMonitor = $('#ucg-run-monitor');
        const $runMonitorTitle = $('#ucg-run-monitor-title');
        const $runMonitorStatus = $('#ucg-run-monitor-status');
        const $runProgressBar = $('#ucg-run-progress-bar');
        const $runMonitorStats = $('#ucg-run-monitor-stats');
        const $runLog = $('#ucg-run-log');
        const $runReviewLink = $('#ucg-run-review-link');
        const $selectionMode = $('input[name="ucg-selection-mode"]');
        const $saveTemplateChanges = $('#ucg-save-template-changes');
        const $createModeTokenWarning = $('#ucg-create-mode-token-warning');
        let $activeTemplateTextarea = $templateBody;
        let lastToastSignature = '';
        let lastToastAt = 0;
        let toastSeq = 0;
        let toastTimersPaused = false;
        const toastById = new Map();
        const toastByKey = new Map();

        function setScenarioCardState() {
            if (!$scenarioCards.length) {
                return;
            }
            $scenarioCards.find('.ucg-scenario-card').removeClass('is-selected');
            $scenarioInputs.filter(':checked').closest('.ucg-scenario-card').addClass('is-selected');
        }

        function getScenario() {
            const selected = String($scenarioInputs.filter(':checked').val() || state.scenario || 'field_update');
            state.scenario = selected || 'field_update';
            setScenarioCardState();
            return state.scenario;
        }

        function scenarioSupportsPublishDateRange(scenario) {
            const normalized = String(scenario || '').trim();
            return normalized === 'comments' || normalized === 'woo_reviews';
        }

        function scenarioSupportsItemsPerPost(scenario) {
            const normalized = String(scenario || '').trim();
            return normalized === 'comments' || normalized === 'woo_reviews';
        }

        function scenarioSupportsWooRatingRange(scenario) {
            const normalized = String(scenario || '').trim();
            return normalized === 'woo_reviews';
        }

        function scenarioSupportsMultiFields(scenario) {
            const normalized = String(scenario || '').trim();
            return normalized === 'post_fields' || normalized === 'product_fields' || normalized === 'image_generation';
        }

        function scenarioRequiresProductPostType(scenario) {
            const normalized = String(scenario || '').trim();
            return normalized === 'product_fields';
        }

        function scenarioSupportsCreateNewMode(scenario) {
            const normalized = String(scenario || '').trim();
            return normalized === 'post_fields' || normalized === 'product_fields';
        }

        function normalizeCreateTopics(value) {
            if (Array.isArray(value)) {
                return value.map(function (item) {
                    return String(item == null ? '' : item).trim();
                }).filter(function (item) {
                    return item !== '';
                }).slice(0, 1000);
            }
            const rawText = String(value == null ? '' : value);
            if (!rawText.trim()) {
                return [];
            }
            return rawText.split(/\r\n|\r|\n/).map(function (line) {
                return String(line || '').trim();
            }).filter(function (line) {
                return line !== '';
            }).slice(0, 1000);
        }

        function collectCreateTopics() {
            if (!$createTopics.length) {
                return [];
            }
            return normalizeCreateTopics($createTopics.val());
        }

        function getAiRowsContainers() {
            return $aiFieldRows.add($seoFieldRows);
        }

        function isSeoAiField(targetField, key) {
            const normalizedTarget = String(targetField || '').trim().toLowerCase();
            const normalizedKey = String(key || '').trim().toLowerCase();
            if (normalizedTarget.indexOf('seo_field:') === 0) {
                return true;
            }
            return normalizedKey === 'seo_title' || normalizedKey === 'seo_description';
        }

        function normalizeAiOutputType(targetField, key, explicitType) {
            const normalizedExplicit = String(explicitType || '').trim().toLowerCase();
            if (normalizedExplicit === 'image') {
                return 'image';
            }
            const normalizedTarget = String(targetField || '').trim().toLowerCase();
            const normalizedKey = String(key || '').trim().toLowerCase();
            if (normalizedTarget.indexOf('media:') === 0) {
                return 'image';
            }
            if (normalizedKey === 'featured_image' || normalizedKey === 'product_images' || normalizedKey === 'product_gallery') {
                return 'image';
            }
            return 'text';
        }

        function isImageAiField(targetField, key, explicitType) {
            return normalizeAiOutputType(targetField, key, explicitType) === 'image';
        }

        function aiFieldSupportsLength(targetField, key) {
            if (isImageAiField(targetField, key)) {
                return false;
            }
            const normalizedTarget = String(targetField || '').trim().toLowerCase();
            const normalizedKey = String(key || '').trim().toLowerCase();
            if (normalizedTarget === 'post:post_title') {
                return false;
            }
            if (normalizedTarget === 'seo_field:title' || normalizedTarget === 'seo_field:description') {
                return false;
            }
            if (normalizedKey === 'post_title' || normalizedKey === 'seo_title' || normalizedKey === 'seo_description') {
                return false;
            }
            return true;
        }

        function getRunTargetMode() {
            const scenario = getScenario();
            if (!scenarioSupportsCreateNewMode(scenario)) {
                return 'update_existing';
            }
            const selected = String($runTargetMode.filter(':checked').val() || 'update_existing');
            return selected === 'create_new' ? 'create_new' : 'update_existing';
        }

        function isCreateNewMode() {
            return getRunTargetMode() === 'create_new';
        }

        function setPostTypeValueSilently(value) {
            if (!$postType.length) {
                return;
            }
            const normalized = String(value == null ? '' : value);
            $postType.val(normalized);
            const el = $postType.get(0);
            if (el && el.tomselect) {
                el.tomselect.setValue(normalized, true);
            }
        }

        function applyPostTypeSelectionVisibility(scenario) {
            const normalizedScenario = String(scenario || '').trim();
            const requiresProduct = scenarioRequiresProductPostType(scenario);
            if (requiresProduct && $postType.find('option[value="product"]').length) {
                if (String($postType.val() || '') !== 'product') {
                    setPostTypeValueSilently('product');
                }
            } else if (normalizedScenario === 'post_fields' && String($postType.val() || '') === 'product') {
                if ($postType.find('option[value="post"]').length) {
                    setPostTypeValueSilently('post');
                } else {
                    const $firstNonProduct = $postType.find('option').filter(function () {
                        return String($(this).val() || '') !== 'product';
                    }).first();
                    if ($firstNonProduct.length) {
                        setPostTypeValueSilently(String($firstNonProduct.val() || ''));
                    }
                }
            }
            if ($postTypeWrap.length) {
                $postTypeWrap.toggle(!requiresProduct);
            }
            return String($postType.val() || '');
        }

        function normalizeItemsPerPost(value) {
            const parsed = Number(value || 1);
            if (!Number.isFinite(parsed)) {
                return 1;
            }
            return Math.max(1, Math.min(50, Math.round(parsed)));
        }

        function normalizeWooRatingValue(value) {
            const parsed = Number(value || 5);
            if (!Number.isFinite(parsed)) {
                return 5;
            }
            return Math.max(1, Math.min(5, Math.round(parsed)));
        }

        function toggleWooRatingRangeControls(scenario) {
            const enabled = scenarioSupportsWooRatingRange(scenario);
            if ($wooRatingRangeWrap.length) {
                $wooRatingRangeWrap.prop('hidden', !enabled).toggle(enabled);
            }
            if (!enabled) {
                if ($wooRatingMin.length) {
                    $wooRatingMin.val('1');
                }
                if ($wooRatingMax.length) {
                    $wooRatingMax.val('5');
                }
            }
        }

        function collectWooRatingRangeForRun(scenario) {
            if (!scenarioSupportsWooRatingRange(scenario)) {
                return { valid: true, ratingMin: 1, ratingMax: 5 };
            }

            let ratingMin = normalizeWooRatingValue($wooRatingMin.val());
            let ratingMax = normalizeWooRatingValue($wooRatingMax.val());

            if (ratingMin > ratingMax) {
                const tmp = ratingMin;
                ratingMin = ratingMax;
                ratingMax = tmp;
            }

            return { valid: true, ratingMin: ratingMin, ratingMax: ratingMax };
        }

        function applyItemsPerPostVisibility(scenario) {
            const enabled = scenarioSupportsItemsPerPost(scenario);
            const hideTargetField = enabled || scenarioSupportsMultiFields(scenario);
            if ($itemsPerPostWrap.length) {
                $itemsPerPostWrap.toggle(enabled);
            }
            if ($targetFieldWrap.length) {
                $targetFieldWrap.toggle(!hideTargetField);
            }
            if (!enabled) {
                if ($itemsPerPost.length) {
                    $itemsPerPost.val('1');
                }
            }
        }

        function normalizeDateInputValue(value) {
            const normalized = String(value || '').trim();
            if (!normalized) {
                return '';
            }
            if (!/^\d{4}-\d{2}-\d{2}$/.test(normalized)) {
                return '';
            }
            return normalized;
        }

        function togglePublishDateRangeControls(scenario) {
            const enabled = scenarioSupportsPublishDateRange(scenario);
            if ($publishDateRangeWrap.length) {
                $publishDateRangeWrap.prop('hidden', !enabled).toggle(enabled);
            }
            if (!enabled) {
                $publishDateFrom.val('');
                $publishDateTo.val('');
            }
        }

        function collectPublishDateRangeForRun(scenario) {
            if (!scenarioSupportsPublishDateRange(scenario)) {
                return { valid: true, from: '', to: '' };
            }

            const rawFrom = String($publishDateFrom.val() || '').trim();
            const rawTo = String($publishDateTo.val() || '').trim();
            let dateFrom = normalizeDateInputValue(rawFrom);
            let dateTo = normalizeDateInputValue(rawTo);

            if (rawFrom && !dateFrom) {
                return { valid: false, message: jsT('Некорректная дата "от". Используйте формат YYYY-MM-DD.') };
            }
            if (rawTo && !dateTo) {
                return { valid: false, message: jsT('Некорректная дата "до". Используйте формат YYYY-MM-DD.') };
            }

            if (dateFrom && !dateTo) {
                dateTo = dateFrom;
            } else if (!dateFrom && dateTo) {
                dateFrom = dateTo;
            }

            if (dateFrom && dateTo && dateFrom > dateTo) {
                return { valid: false, message: jsT('Дата "от" не может быть больше даты "до".') };
            }

            return {
                valid: true,
                from: dateFrom || '',
                to: dateTo || ''
            };
        }

        function lengthOptionsSource() {
            const source = Array.isArray(state.schema.text_length_options) ? state.schema.text_length_options : [];
            if (source.length) {
                return source;
            }
            return [
                { id: 1, name: jsT('Короткое') },
                { id: 2, name: jsT('Стандартное') },
                { id: 3, name: jsT('Расширенное') },
                { id: 4, name: jsT('Большое') }
            ];
        }

        function aiFieldPresets() {
            return Array.isArray(state.schema.ai_field_presets) ? state.schema.ai_field_presets : [];
        }

        function imageGenerationModelsSource() {
            const models = Array.isArray(state.schema.image_generation_models) ? state.schema.image_generation_models : [];
            return models.filter(function (item) {
                if (!item || typeof item !== 'object') {
                    return false;
                }
                const id = String(item.id || '').trim();
                const name = String(item.name || id).trim();
                return id !== '' && name !== '';
            });
        }

        function staticFieldPresets() {
            return Array.isArray(state.schema.static_field_presets) ? state.schema.static_field_presets : [];
        }

        function isRemovedProductStaticFieldKey(key) {
            const normalized = String(key || '').trim().toLowerCase();
            return normalized === 'regular_price' || normalized === 'sale_price' || normalized === 'sku';
        }

        function remapLegacyProductStaticField(key, targetField, scenario) {
            const normalizedScenario = String(scenario || '').trim().toLowerCase();
            let normalizedKey = String(key || '').trim();
            let normalizedTarget = String(targetField || '').trim();
            if (normalizedScenario !== 'product_fields') {
                return { key: normalizedKey, target_field: normalizedTarget };
            }

            const loweredKey = normalizedKey.toLowerCase();
            if (loweredKey === 'post_category') {
                normalizedKey = 'product_category';
            } else if (loweredKey === 'post_tag') {
                normalizedKey = 'product_tag';
            }

            const loweredTarget = normalizedTarget.toLowerCase();
            if (loweredTarget === 'tax:category') {
                normalizedTarget = 'tax:product_cat';
            } else if (loweredTarget === 'tax:post_tag') {
                normalizedTarget = 'tax:product_tag';
            }

            return { key: normalizedKey, target_field: normalizedTarget };
        }

        function normalizeArrayFieldValue(value) {
            if (Array.isArray(value)) {
                return value.map(function (item) {
                    return String(item == null ? '' : item);
                }).filter(function (item) {
                    return item !== '';
                });
            }
            if (value == null) {
                return [];
            }
            const raw = String(value).trim();
            if (!raw) {
                return [];
            }
            if (raw.indexOf(',') !== -1) {
                return raw.split(',').map(function (item) {
                    return String(item || '').trim();
                }).filter(function (item) {
                    return item !== '';
                });
            }
            return [raw];
        }

        function normalizeTargetFieldByKeyInWizard(key) {
            const normalized = String(key || '').trim().toLowerCase();
            const map = {
                post_title: 'post:post_title',
                post_content: 'post:post_content',
                post_excerpt: 'post:post_excerpt',
                seo_title: 'seo_field:title',
                seo_description: 'seo_field:description',
                title: 'seo_field:title',
                description: 'seo_field:description',
                post_status: 'post:post_status',
                post_author: 'post:post_author',
                post_date: 'post:post_date',
                post_category: 'tax:category',
                post_tag: 'tax:post_tag',
                product_category: 'tax:product_cat',
                product_tag: 'tax:product_tag',
                stock_status: 'meta:_stock_status',
                stock_quantity: 'meta:_stock',
                catalog_visibility: 'meta:_visibility',
                featured_image: 'media:featured',
                product_images: 'media:product_images',
                product_gallery: 'media:gallery'
            };
            if (Object.prototype.hasOwnProperty.call(map, normalized)) {
                return map[normalized];
            }
            if (normalized.indexOf('post_') === 0) {
                return 'post:' + normalized;
            }
            if (normalized.indexOf('meta_') === 0) {
                return 'meta:' + normalized.substring(5);
            }
            if (normalized.indexOf('tax_') === 0) {
                return 'tax:' + normalized.substring(4);
            }
            return '';
        }

        function findFieldByKey(fields, key) {
            const normalizedKey = String(key || '').trim();
            if (!normalizedKey || !Array.isArray(fields)) {
                return null;
            }
            for (let i = 0; i < fields.length; i += 1) {
                const row = fields[i];
                if (!row || typeof row !== 'object') {
                    continue;
                }
                if (String(row.key || '') === normalizedKey) {
                    return row;
                }
            }
            return null;
        }

        function normalizeFieldBoolean(value, defaultValue) {
            if (typeof value === 'boolean') {
                return value;
            }
            if (value === null || typeof value === 'undefined') {
                return !!defaultValue;
            }
            const normalized = String(value).trim().toLowerCase();
            if (!normalized) {
                return !!defaultValue;
            }
            if (normalized === '0' || normalized === 'false' || normalized === 'off' || normalized === 'no') {
                return false;
            }
            return true;
        }

        function normalizeTemplateFieldsInput(rawFields) {
            if (Array.isArray(rawFields)) {
                return rawFields;
            }
            if (!rawFields || typeof rawFields !== 'object') {
                return [];
            }
            const rows = [];
            Object.keys(rawFields).forEach(function (key) {
                const row = rawFields[key];
                if (!row || typeof row !== 'object') {
                    return;
                }
                if (!Object.prototype.hasOwnProperty.call(row, 'key')) {
                    rows.push($.extend({ key: String(key) }, row));
                    return;
                }
                rows.push(row);
            });
            return rows;
        }

        function normalizeTemplateAiFields(rawFields) {
            const source = normalizeTemplateFieldsInput(rawFields);
            const normalized = [];
            source.forEach(function (field, index) {
                if (!field || typeof field !== 'object') {
                    return;
                }
                const key = String(field.key || field.id || field.slug || ('ai_' + (index + 1))).trim();
                if (!key) {
                    return;
                }
                const targetField = String(field.target_field || field.targetField || normalizeTargetFieldByKeyInWizard(key)).trim();
                const prompt = String(
                    field.prompt ||
                    field.prompt_template ||
                    field.template_prompt ||
                    field.body ||
                    field.text ||
                    field.template ||
                    ''
                );
                const lengthOptionId = Number(
                    field.length_option_id ||
                    field.lengthOptionId ||
                    field.length_id ||
                    0
                );
                const maxChars = Number(field.max_chars || field.maxChars || 0);
                const outputType = normalizeAiOutputType(
                    targetField,
                    key,
                    field.output_type || field.outputType || ''
                );
                const fieldModel = String(field.model || field.model_id || 'auto').trim() || 'auto';
                const imagesCountRaw = Number(field.images_count || field.imagesCount || 1);
                const imagesCount = Number.isFinite(imagesCountRaw) ? Math.max(1, Math.min(8, Math.round(imagesCountRaw))) : 1;
                const aspectRatio = String(field.aspect_ratio || field.aspectRatio || '').trim();
                const imageSize = String(field.image_size || field.imageSize || '').trim().toUpperCase();
                normalized.push({
                    key: key,
                    label: String(field.label || field.name || key),
                    enabled: normalizeFieldBoolean(field.enabled, true),
                    target_field: targetField,
                    prompt: prompt,
                    length_option_id: lengthOptionId > 0 ? lengthOptionId : 0,
                    max_chars: maxChars > 0 ? maxChars : 0,
                    output_type: outputType,
                    model: fieldModel,
                    images_count: imagesCount,
                    aspect_ratio: aspectRatio,
                    image_size: imageSize
                });
            });
            return normalized;
        }

        function normalizeTemplateStaticFields(rawFields, scenario) {
            const source = normalizeTemplateFieldsInput(rawFields);
            const normalized = [];
            const normalizedScenario = String(scenario || getScenario() || '').trim();
            const usedKeys = new Set();
            source.forEach(function (field, index) {
                if (!field || typeof field !== 'object') {
                    return;
                }
                let key = String(field.key || field.id || field.slug || ('static_' + (index + 1))).trim();
                if (!key) {
                    return;
                }
                let targetField = String(field.target_field || field.targetField || normalizeTargetFieldByKeyInWizard(key)).trim();
                const remapped = remapLegacyProductStaticField(key, targetField, normalizedScenario);
                key = String(remapped.key || '').trim();
                targetField = String(remapped.target_field || '').trim();
                if (!key) {
                    return;
                }
                if (!targetField) {
                    targetField = normalizeTargetFieldByKeyInWizard(key);
                }
                if (usedKeys.has(key)) {
                    return;
                }
                usedKeys.add(key);
                const inputType = String(field.input_type || field.inputType || 'text').trim();
                const options = Array.isArray(field.options) ? field.options : [];
                const value = Object.prototype.hasOwnProperty.call(field, 'value') ? field.value : '';
                const placeholder = String(field.placeholder || '');
                const hint = String(field.hint || '');
                normalized.push({
                    key: key,
                    label: String(field.label || field.name || key),
                    enabled: normalizeFieldBoolean(field.enabled, false),
                    target_field: targetField,
                    input_type: inputType || 'text',
                    options: options,
                    value: value,
                    placeholder: placeholder,
                    hint: hint
                });
            });
            return normalized;
        }

        function mergeStaticPresetWithRuntime(preset, runtimeField) {
            const merged = $.extend({}, preset || {});
            if (!runtimeField || typeof runtimeField !== 'object') {
                return merged;
            }
            if (Object.prototype.hasOwnProperty.call(runtimeField, 'enabled')) {
                merged.enabled = normalizeFieldBoolean(runtimeField.enabled, !!merged.enabled);
            }
            if (Object.prototype.hasOwnProperty.call(runtimeField, 'value')) {
                merged.value = runtimeField.value;
            }
            return merged;
        }

        function normalizeTaxonomyChecklistOptions(rawOptions) {
            if (!Array.isArray(rawOptions)) {
                return [];
            }

            const normalizeNode = function (node) {
                if (!node || typeof node !== 'object') {
                    return null;
                }
                const value = String(
                    node.value ||
                    node.id ||
                    ''
                ).trim();
                if (!value) {
                    return null;
                }
                const label = String(node.label || node.name || value);
                const childrenSource = Array.isArray(node.children) ? node.children : [];
                const children = [];
                childrenSource.forEach(function (childNode) {
                    const normalizedChild = normalizeNode(childNode);
                    if (normalizedChild) {
                        children.push(normalizedChild);
                    }
                });
                return {
                    value: value,
                    label: label,
                    children: children
                };
            };

            const normalized = [];
            rawOptions.forEach(function (option) {
                const normalizedNode = normalizeNode(option);
                if (normalizedNode) {
                    normalized.push(normalizedNode);
                }
            });
            return normalized;
        }

        function buildTaxonomyChecklistHtml(nodes, selectedValuesSet, level) {
            const items = Array.isArray(nodes) ? nodes : [];
            if (!items.length) {
                return '';
            }
            const depth = Math.max(0, Number(level || 0));
            const listClass = depth > 0
                ? 'ucg-static-tax-checklist ucg-static-tax-checklist--child'
                : 'ucg-static-tax-checklist';
            let html = '<ul class="' + listClass + '">';
            items.forEach(function (node) {
                if (!node || typeof node !== 'object') {
                    return;
                }
                const value = String(node.value || '').trim();
                if (!value) {
                    return;
                }
                const label = String(node.label || value);
                const checked = selectedValuesSet && selectedValuesSet.has(value) ? ' checked' : '';
                const children = Array.isArray(node.children) ? node.children : [];
                html += '' +
                    '<li class="ucg-static-tax-checklist__item">' +
                    '  <label class="ucg-static-tax-checklist__label">' +
                    '    <input type="checkbox" class="ucg-static-field-value-check" value="' + escapeHtml(value) + '"' + checked + '>' +
                    '    <span>' + escapeHtml(label) + '</span>' +
                    '  </label>' +
                    (children.length ? buildTaxonomyChecklistHtml(children, selectedValuesSet, depth + 1) : '') +
                    '</li>';
            });
            html += '</ul>';
            return html;
        }

        function setFieldControlDisabled($controls, disabled) {
            if (!$controls || !$controls.length) {
                return;
            }
            const shouldDisable = !!disabled;
            $controls.each(function () {
                const element = this;
                const $element = $(element);
                $element.prop('disabled', shouldDisable);
                if (element && element.tomselect) {
                    if (shouldDisable) {
                        element.tomselect.disable();
                    } else {
                        element.tomselect.enable();
                    }
                }
            });
        }

        function toggleAiFieldRow($row) {
            if (!$row || !$row.length || !getAiRowsContainers().length) {
                return;
            }
            if (!$row.find('.ucg-ai-field-enabled').is(':checked')) {
                return;
            }
            const shouldOpen = !$row.hasClass('is-open');
            $row.toggleClass('is-open', shouldOpen);
            $row.find('.ucg-ai-field-row__body').prop('hidden', !shouldOpen);
            $row.find('.ucg-ai-field-toggle').attr('aria-expanded', shouldOpen ? 'true' : 'false');
            const $prompt = $row.find('.ucg-ai-field-prompt').first();
            if (shouldOpen && $prompt.length) {
                $activeTemplateTextarea = $prompt;
            }
        }

        function refreshAiFieldRowsUi() {
            if (!getAiRowsContainers().length) {
                return;
            }

            const updateRowsState = function ($rows) {
                let enabledCount = 0;
                $rows.each(function () {
                    const $row = $(this);
                    const enabled = $row.find('.ucg-ai-field-enabled').is(':checked');
                    const supportsLength = String($row.attr('data-supports-length') || '1') !== '0';
                    const outputType = String($row.attr('data-output-type') || 'text').toLowerCase();
                    const isImage = outputType === 'image';
                    const $prompt = $row.find('.ucg-ai-field-prompt');
                    const $length = $row.find('.ucg-ai-field-length');
                    const $lengthWrap = $row.find('.ucg-ai-field-row__length-wrap');
                    const $imageModel = $row.find('.ucg-ai-field-image-model');
                    const $imageCount = $row.find('.ucg-ai-field-images-count');
                    const $aspectRatio = $row.find('.ucg-ai-field-aspect-ratio');
                    const $imageSize = $row.find('.ucg-ai-field-image-size');
                    const hasOpenClass = $row.hasClass('is-open');
                    const isOpen = enabled && hasOpenClass;

                    if (enabled) {
                        enabledCount += 1;
                    }

                    $row.toggleClass('is-disabled', !enabled);
                    $row.toggleClass('is-length-hidden', !supportsLength);
                    setFieldControlDisabled($prompt, !enabled);
                    setFieldControlDisabled($length, !enabled || !supportsLength);
                    setFieldControlDisabled($imageModel, !enabled || !isImage);
                    setFieldControlDisabled($imageCount, !enabled || !isImage);
                    setFieldControlDisabled($aspectRatio, !enabled || !isImage);
                    setFieldControlDisabled($imageSize, !enabled || !isImage);
                    if ($lengthWrap.length) {
                        $lengthWrap.prop('hidden', !supportsLength);
                    }
                    $row.find('.ucg-ai-field-row__image-model-wrap').prop('hidden', !isImage);
                    $row.find('.ucg-ai-field-row__image-count-wrap').prop('hidden', !isImage);
                    $row.find('.ucg-ai-field-row__image-aspect-wrap').prop('hidden', !isImage);
                    $row.find('.ucg-ai-field-row__image-size-wrap').prop('hidden', !isImage);
                    $row.toggleClass('is-open', isOpen);
                    $row.find('.ucg-ai-field-row__body').prop('hidden', !isOpen);
                    $row.find('.ucg-ai-field-toggle').attr('aria-expanded', isOpen ? 'true' : 'false');
                });
                return enabledCount;
            };

            const aiEnabledCount = updateRowsState($aiFieldRows.find('.ucg-ai-field-row'));
            const seoRows = $seoFieldRows.find('.ucg-ai-field-row');
            const seoEnabledCount = updateRowsState(seoRows);
            const hasSeoRows = seoRows.length > 0;

            if ($seoFieldSection.length) {
                $seoFieldSection.prop('hidden', !hasSeoRows).toggle(hasSeoRows);
            }

            if ($aiFieldEnabledCount.length) {
                $aiFieldEnabledCount.text(String(aiEnabledCount));
            }
            if ($seoFieldEnabledCount.length) {
                $seoFieldEnabledCount.text(String(seoEnabledCount));
            }
        }

        function truncateFieldBadgeValue(value) {
            const text = String(value || '').trim();
            if (!text) {
                return '—';
            }
            if (text.length <= 52) {
                return text;
            }
            return text.slice(0, 49) + '...';
        }

        function collectStaticFieldPreviewValue($row, enabled) {
            if (!$row || !$row.length || !enabled) {
                return '—';
            }
            const inputType = String($row.attr('data-input-type') || 'text');
            const $input = $row.find('.ucg-static-field-value').first();

            if (inputType === 'multiselect') {
                if (!$input.length) {
                    return '—';
                }
                const selectedValues = normalizeArrayFieldValue($input.val());
                if (!selectedValues.length) {
                    return '—';
                }
                const labelsByValue = {};
                $input.find('option').each(function () {
                    const value = String($(this).attr('value') || '');
                    labelsByValue[value] = String($(this).text() || '').trim();
                });
                return truncateFieldBadgeValue(selectedValues.map(function (value) {
                    return labelsByValue[value] || value;
                }).join(', '));
            }

            if (inputType === 'select') {
                if (!$input.length) {
                    return '—';
                }
                const label = String($input.find('option:selected').first().text() || '').trim();
                return truncateFieldBadgeValue(label);
            }

            if (inputType === 'taxonomy_checklist') {
                const labels = [];
                $row.find('.ucg-static-field-value-check:checked').each(function () {
                    const label = String($(this).closest('label').find('span').first().text() || '').trim();
                    if (label) {
                        labels.push(label);
                    }
                });
                return truncateFieldBadgeValue(labels.join(', '));
            }

            if (!$input.length) {
                return '—';
            }
            const value = String($input.val() == null ? '' : $input.val()).trim();
            return truncateFieldBadgeValue(value);
        }

        function refreshStaticFieldRowsUi() {
            if (!$staticFieldRows.length) {
                return;
            }
            let enabledCount = 0;
            $staticFieldRows.find('.ucg-static-field-row').each(function () {
                const $row = $(this);
                const enabled = $row.find('.ucg-static-field-enabled').is(':checked');
                const previewValue = collectStaticFieldPreviewValue($row, enabled);
                const $body = $row.find('.ucg-static-field-row__body');
                if (enabled) {
                    enabledCount += 1;
                }
                $row.toggleClass('is-disabled', !enabled);
                setFieldControlDisabled($row.find('.ucg-static-field-value, .ucg-static-field-value-check'), !enabled);
                $body.prop('hidden', !enabled);
                $row.find('.ucg-static-field-value-badge')
                    .text(previewValue)
                    .toggleClass('is-active', enabled && previewValue !== '—');
            });
            if ($staticFieldEnabledCount.length) {
                $staticFieldEnabledCount.text(String(enabledCount));
            }
        }

        function renderAiFieldRows(templateFields) {
            if (!$aiFieldRows.length && !$seoFieldRows.length) {
                return;
            }
            const presets = aiFieldPresets();
            const template = normalizeTemplateAiFields(templateFields);
            const hasTemplateFields = template.length > 0;
            const renderedKeys = new Set();
            const sourceRows = [];
            const defaultPromptByKey = {};

            presets.forEach(function (preset) {
                if (!preset || typeof preset !== 'object') {
                    return;
                }
                const presetKey = String(preset.key || '');
                if (presetKey) {
                    defaultPromptByKey[presetKey] = String(preset.prompt || '');
                }
                const templateField = findFieldByKey(template, preset.key);
                const row = $.extend({}, preset, templateField || {});
                if (
                    templateField &&
                    String(templateField.prompt || '').trim() === '' &&
                    String(preset.prompt || '').trim() !== ''
                ) {
                    row.prompt = String(preset.prompt || '');
                }
                if (!templateField && hasTemplateFields) {
                    row.enabled = false;
                }
                sourceRows.push(row);
                renderedKeys.add(String(preset.key || ''));
            });
            template.forEach(function (field) {
                if (!field || typeof field !== 'object') {
                    return;
                }
                const key = String(field.key || '');
                if (renderedKeys.has(key)) {
                    return;
                }
                if (String(field.prompt || '').trim() === '' && String(defaultPromptByKey[key] || '').trim() !== '') {
                    field.prompt = String(defaultPromptByKey[key] || '');
                }
                sourceRows.push(field);
            });

            if (!sourceRows.length) {
                if ($aiFieldEnabledCount.length) {
                    $aiFieldEnabledCount.text('0');
                }
                if ($seoFieldEnabledCount.length) {
                    $seoFieldEnabledCount.text('0');
                }
                if ($aiFieldRows.length) {
                    $aiFieldRows.html('<p class="ucg-muted">' + escapeHtml(jsT('Для этого сценария нет доступных AI-полей.')) + '</p>');
                }
                if ($seoFieldRows.length) {
                    $seoFieldRows.html('');
                }
                if ($seoFieldSection.length) {
                    $seoFieldSection.prop('hidden', true).hide();
                }
                return;
            }

            const lengthOptions = lengthOptionsSource();
            const standardRows = [];
            const seoRows = [];

            sourceRows.forEach(function (field) {
                if (!field || typeof field !== 'object') {
                    return;
                }
                const key = String(field.key || '').trim();
                const targetField = String(field.target_field || '').trim();
                if (isSeoAiField(targetField, key)) {
                    seoRows.push(field);
                    return;
                }
                standardRows.push(field);
            });

            const buildRowHtml = function (field, index) {
                const key = String(field && field.key ? field.key : ('ai_' + (index + 1)));
                const label = String(field && field.label ? field.label : key);
                const enabled = !Object.prototype.hasOwnProperty.call(field || {}, 'enabled') || !!field.enabled;
                const prompt = String(field && field.prompt ? field.prompt : '');
                const targetField = String(field && field.target_field ? field.target_field : '');
                const outputType = normalizeAiOutputType(targetField, key, field && field.output_type ? field.output_type : '');
                const isImage = outputType === 'image';
                const supportsLength = !isImage && aiFieldSupportsLength(targetField, key);
                let lengthId = Number(field && field.length_option_id ? field.length_option_id : 0);
                const maxChars = Number(field && field.max_chars ? field.max_chars : 0);
                if (lengthId <= 0) {
                    lengthId = Number(state.schema.default_length_option_id || 0);
                }
                if (lengthId <= 0 && lengthOptions.length) {
                    lengthId = Number(lengthOptions[0] && lengthOptions[0].id ? lengthOptions[0].id : 0);
                }

                let lengthOptionsHtml = '';
                lengthOptions.forEach(function (option) {
                    const id = Number(option && option.id ? option.id : 0);
                    if (!id) {
                        return;
                    }
                    const optionLabel = option && option.name ? String(option.name) : ('#' + id);
                    const selected = id === lengthId ? ' selected' : '';
                    lengthOptionsHtml += '<option value="' + id + '"' + selected + '>' + escapeHtml(optionLabel) + '</option>';
                });
                if (!lengthOptionsHtml) {
                    lengthOptionsHtml = '<option value="0">' + escapeHtml(jsT('Нет опций длины')) + '</option>';
                }
                const imageModels = imageGenerationModelsSource();
                let imageModel = String(field && field.model ? field.model : 'auto').trim() || 'auto';
                if (!imageModels.some(function (modelItem) { return String(modelItem && modelItem.id ? modelItem.id : '') === imageModel; })) {
                    imageModel = 'auto';
                }
                let imageModelOptionsHtml = '';
                imageModels.forEach(function (modelItem) {
                    if (!modelItem || typeof modelItem !== 'object') {
                        return;
                    }
                    const modelId = String(modelItem.id || '').trim();
                    if (!modelId) {
                        return;
                    }
                    const modelLabel = String(modelItem.name || modelId);
                    const selected = modelId === imageModel ? ' selected' : '';
                    imageModelOptionsHtml += '<option value="' + escapeHtml(modelId) + '"' + selected + '>' + escapeHtml(modelLabel) + '</option>';
                });
                if (!imageModelOptionsHtml) {
                    imageModelOptionsHtml = '<option value="auto">auto</option>';
                }
                const imagesCountRaw = Number(field && field.images_count ? field.images_count : 1);
                const imagesCount = Number.isFinite(imagesCountRaw) ? Math.max(1, Math.min(8, Math.round(imagesCountRaw))) : 1;
                const aspectRatio = String(field && field.aspect_ratio ? field.aspect_ratio : '').trim();
                const imageSize = String(field && field.image_size ? field.image_size : '').trim().toUpperCase();
                const aspectOptions = ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'];
                let aspectOptionsHtml = '<option value="">' + escapeHtml(jsT('По умолчанию')) + '</option>';
                aspectOptions.forEach(function (ratio) {
                    const selected = ratio === aspectRatio ? ' selected' : '';
                    aspectOptionsHtml += '<option value="' + escapeHtml(ratio) + '"' + selected + '>' + escapeHtml(ratio) + '</option>';
                });
                const sizeOptions = ['0.5K', '1K', '2K', '4K'];
                let sizeOptionsHtml = '<option value="">' + escapeHtml(jsT('По умолчанию')) + '</option>';
                sizeOptions.forEach(function (sizeItem) {
                    const selected = sizeItem === imageSize ? ' selected' : '';
                    sizeOptionsHtml += '<option value="' + escapeHtml(sizeItem) + '"' + selected + '>' + escapeHtml(sizeItem) + '</option>';
                });
                const initiallyOpen = false;
                return '' +
                    '<div class="ucg-ai-field-row' + (enabled ? '' : ' is-disabled') + '" data-key="' + escapeHtml(key) + '" data-target-field="' + escapeHtml(targetField) + '" data-max-chars="' + (maxChars > 0 ? maxChars : 0) + '" data-supports-length="' + (supportsLength ? '1' : '0') + '" data-output-type="' + escapeHtml(outputType) + '">' +
                    '  <div class="ucg-ai-field-row__head">' +
                    '    <label class="ucg-ai-field-check">' +
                    '      <input type="checkbox" class="ucg-ai-field-enabled"' + (enabled ? ' checked' : '') + '>' +
                    '      <span class="ucg-ai-field-label">' + escapeHtml(label) + '</span>' +
                    '    </label>' +
                    '    <div class="ucg-ai-field-row__meta">' +
                    '      <button type="button" class="ucg-ai-field-toggle" aria-label="' + escapeHtml(jsT('Развернуть поле')) + '" aria-expanded="' + (initiallyOpen ? 'true' : 'false') + '">' +
                    '        <span class="ucg-ai-field-toggle__label">' + escapeHtml(jsT('Настроить')) + '</span>' +
                    '        <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>' +
                    '      </button>' +
                    '    </div>' +
                    '  </div>' +
                    '  <div class="ucg-ai-field-row__body"' + (initiallyOpen ? '' : ' hidden') + '>' +
                    '    <div class="ucg-ai-field-row__grid">' +
                    '      <label class="ucg-field ucg-ai-field-row__prompt-wrap">' +
                    '        <span>' + escapeHtml(jsT('Промпт')) + '</span>' +
                    '        <textarea class="ucg-wizard-template-input ucg-ai-field-prompt" rows="4" placeholder="' + escapeHtml(jsT('Инструкция для генерации этого поля')) + '">' + escapeHtml(prompt) + '</textarea>' +
                    '      </label>' +
                    '      <label class="ucg-field ucg-ai-field-row__length-wrap"' + (supportsLength ? '' : ' hidden') + '>' +
                    '        <span>' + escapeHtml(jsT('Длина')) + '</span>' +
                    '        <select class="ucg-ai-field-length ucg-enhanced-select" data-search-enabled="false">' + lengthOptionsHtml + '</select>' +
                    '      </label>' +
                    '      <label class="ucg-field ucg-ai-field-row__image-model-wrap"' + (isImage ? '' : ' hidden') + '>' +
                    '        <span>' + escapeHtml(jsT('Модель изображения')) + '</span>' +
                    '        <select class="ucg-ai-field-image-model ucg-enhanced-select" data-search-enabled="true" data-search-in-dropdown="true" data-search-placeholder="' + escapeHtml(jsT('Поиск модели...')) + '" data-search-fields="text,value" data-max-options="1000">' + imageModelOptionsHtml + '</select>' +
                    '      </label>' +
                    '      <label class="ucg-field ucg-ai-field-row__image-count-wrap"' + (isImage ? '' : ' hidden') + '>' +
                    '        <span>' + escapeHtml(jsT('Кол-во изображений')) + '</span>' +
                    '        <input type="number" class="ucg-ai-field-images-count" min="1" max="8" step="1" value="' + String(imagesCount) + '">' +
                    '      </label>' +
                    '      <label class="ucg-field ucg-ai-field-row__image-aspect-wrap"' + (isImage ? '' : ' hidden') + '>' +
                    '        <span>' + escapeHtml(jsT('Соотношение сторон')) + '</span>' +
                    '        <select class="ucg-ai-field-aspect-ratio ucg-enhanced-select" data-search-enabled="false">' + aspectOptionsHtml + '</select>' +
                    '      </label>' +
                    '      <label class="ucg-field ucg-ai-field-row__image-size-wrap"' + (isImage ? '' : ' hidden') + '>' +
                    '        <span>' + escapeHtml(jsT('Размер')) + '</span>' +
                    '        <select class="ucg-ai-field-image-size ucg-enhanced-select" data-search-enabled="false">' + sizeOptionsHtml + '</select>' +
                    '      </label>' +
                    '    </div>' +
                    '  </div>' +
                    '</div>';
            };

            let standardHtml = '';
            standardRows.forEach(function (field, index) {
                standardHtml += buildRowHtml(field, index);
            });
            let seoHtml = '';
            seoRows.forEach(function (field, index) {
                seoHtml += buildRowHtml(field, index);
            });

            if ($aiFieldRows.length) {
                $aiFieldRows.html(
                    standardHtml || ('<p class="ucg-muted">' + escapeHtml(jsT('Для этого сценария нет доступных AI-полей.')) + '</p>')
                );
            }
            if ($seoFieldRows.length) {
                $seoFieldRows.html(seoHtml);
            }
            if ($seoFieldSection.length) {
                const hasSeoRows = seoRows.length > 0;
                $seoFieldSection.prop('hidden', !hasSeoRows).toggle(hasSeoRows);
            }

            initEnhancedSelects(getAiRowsContainers().find('.ucg-enhanced-select'));
            refreshAiFieldRowsUi();
        }

        function renderStaticFieldRows(staticFields) {
            if (!$staticFieldRows.length) {
                return;
            }

            const scenario = getScenario();
            const isProductScenario = scenario === 'product_fields';
            const presets = staticFieldPresets();
            const runtimeFields = normalizeTemplateStaticFields(staticFields, scenario);
            const sourceRows = [];
            const renderedKeys = new Set();
            presets.forEach(function (preset) {
                if (!preset || typeof preset !== 'object') {
                    return;
                }
                const key = String(preset.key || '');
                if (isProductScenario && isRemovedProductStaticFieldKey(key)) {
                    return;
                }
                sourceRows.push(mergeStaticPresetWithRuntime(preset, findFieldByKey(runtimeFields, key)));
                renderedKeys.add(key);
            });
            runtimeFields.forEach(function (field, index) {
                if (!field || typeof field !== 'object') {
                    return;
                }
                const key = String(field.key || ('static_' + (index + 1)));
                if (isProductScenario && isRemovedProductStaticFieldKey(key)) {
                    return;
                }
                if (renderedKeys.has(key)) {
                    return;
                }
                sourceRows.push(field);
            });

            if (!sourceRows.length) {
                if ($staticFieldEnabledCount.length) {
                    $staticFieldEnabledCount.text('0');
                }
                $staticFieldRows.html('<p class="ucg-muted">' + escapeHtml(jsT('Для этого типа записей нет static-полей.')) + '</p>');
                return;
            }

            let html = '';
            sourceRows.forEach(function (field, index) {
                const key = String(field && field.key ? field.key : ('static_' + (index + 1)));
                const label = String(field && field.label ? field.label : key);
                const enabled = !!(field && field.enabled);
                const targetField = String(field && field.target_field ? field.target_field : '');
                const inputType = String(field && field.input_type ? field.input_type : 'text');
                const options = Array.isArray(field && field.options) ? field.options : [];
                const value = field && Object.prototype.hasOwnProperty.call(field, 'value') ? field.value : '';
                const placeholder = String(field && field.placeholder ? field.placeholder : '');
                const hint = String(field && field.hint ? field.hint : '');
                let controlHtml = '';

                if (inputType === 'taxonomy_checklist') {
                    const selectedValues = new Set(normalizeArrayFieldValue(value));
                    const checklistNodes = normalizeTaxonomyChecklistOptions(options);
                    if (checklistNodes.length) {
                        controlHtml = buildTaxonomyChecklistHtml(checklistNodes, selectedValues, 0);
                    } else {
                        controlHtml = '<p class="ucg-muted">' + escapeHtml(jsT('Нет доступных терминов.')) + '</p>';
                    }
                } else if (inputType === 'select' || inputType === 'multiselect') {
                    const selectedValues = inputType === 'multiselect'
                        ? normalizeArrayFieldValue(value)
                        : [String(value == null ? '' : value)];
                    let optionsHtml = '';
                    options.forEach(function (option) {
                        const optionValue = option && Object.prototype.hasOwnProperty.call(option, 'value')
                            ? String(option.value)
                            : '';
                        const optionLabel = option && Object.prototype.hasOwnProperty.call(option, 'label')
                            ? String(option.label)
                            : optionValue;
                        const selected = selectedValues.indexOf(optionValue) !== -1 ? ' selected' : '';
                        optionsHtml += '<option value="' + escapeHtml(optionValue) + '"' + selected + '>' + escapeHtml(optionLabel) + '</option>';
                    });
                    const multipleAttr = inputType === 'multiselect' ? ' multiple size="5"' : '';
                    const cls = inputType === 'multiselect'
                        ? 'ucg-static-field-value ucg-static-field-value--multi'
                        : 'ucg-static-field-value ucg-enhanced-select';
                    const selectAttrs = inputType === 'multiselect' ? '' : ' data-search-enabled="false"';
                    controlHtml = '<select class="' + cls + '"' + selectAttrs + multipleAttr + '>' + optionsHtml + '</select>';
                } else if (inputType === 'number') {
                    controlHtml = '<input type="number" class="ucg-static-field-value" value="' + escapeHtml(String(value == null ? '' : value)) + '" step="0.01" placeholder="' + escapeHtml(placeholder || jsT('Значение')) + '">';
                } else {
                    controlHtml = '<input type="text" class="ucg-static-field-value" value="' + escapeHtml(String(value == null ? '' : value)) + '" placeholder="' + escapeHtml(placeholder || jsT('Значение')) + '">';
                }

                html += '' +
                    '<div class="ucg-static-field-row' + (enabled ? '' : ' is-disabled') + '" data-key="' + escapeHtml(key) + '" data-target-field="' + escapeHtml(targetField) + '" data-input-type="' + escapeHtml(inputType) + '">' +
                    '  <div class="ucg-static-field-row__head">' +
                    '    <label class="ucg-static-field-check">' +
                    '      <input type="checkbox" class="ucg-static-field-enabled"' + (enabled ? ' checked' : '') + '>' +
                    '      <span class="ucg-static-field-label">' + escapeHtml(label) + '</span>' +
                    '    </label>' +
                    '    <span class="ucg-static-field-value-badge">—</span>' +
                    '  </div>' +
                    '  <div class="ucg-static-field-row__body"' + (enabled ? '' : ' hidden') + '>' +
                    '    <div class="ucg-static-field-row__control">' + controlHtml + '</div>' +
                    (hint ? ('<p class="ucg-muted ucg-field-hint">' + escapeHtml(hint) + '</p>') : '') +
                    '  </div>' +
                    '</div>';
            });

            $staticFieldRows.html(html);
            initEnhancedSelects($staticFieldRows.find('.ucg-enhanced-select'));
            refreshStaticFieldRowsUi();
        }

        function collectAiFieldsForRun() {
            const fields = [];
            if (!getAiRowsContainers().length) {
                return fields;
            }

            getAiRowsContainers().find('.ucg-ai-field-row').each(function () {
                const $row = $(this);
                const key = String($row.data('key') || '');
                const targetField = String($row.attr('data-target-field') || '');
                const maxChars = Number($row.attr('data-max-chars') || 0);
                const enabled = $row.find('.ucg-ai-field-enabled').is(':checked');
                const label = String($row.find('.ucg-ai-field-label').first().text() || '').trim();
                const prompt = String($row.find('.ucg-ai-field-prompt').val() || '').trim();
                const lengthOptionId = Number($row.find('.ucg-ai-field-length').val() || 0);
                const outputType = normalizeAiOutputType(targetField, key, $row.attr('data-output-type') || 'text');
                const imageModel = String($row.find('.ucg-ai-field-image-model').val() || 'auto').trim() || 'auto';
                const imagesCountRaw = Number($row.find('.ucg-ai-field-images-count').val() || 1);
                const imagesCount = Number.isFinite(imagesCountRaw) ? Math.max(1, Math.min(8, Math.round(imagesCountRaw))) : 1;
                const aspectRatio = String($row.find('.ucg-ai-field-aspect-ratio').val() || '').trim();
                const imageSize = String($row.find('.ucg-ai-field-image-size').val() || '').trim().toUpperCase();

                fields.push({
                    key: key,
                    label: label || key,
                    enabled: enabled,
                    target_field: targetField,
                    prompt: prompt,
                    length_option_id: lengthOptionId > 0 ? lengthOptionId : 0,
                    max_chars: maxChars > 0 ? maxChars : 0,
                    output_type: outputType,
                    model: imageModel,
                    images_count: imagesCount,
                    aspect_ratio: aspectRatio,
                    image_size: imageSize
                });
            });

            return fields;
        }

        function collectStaticFieldsForRun() {
            const fields = [];
            if (!$staticFieldRows.length) {
                return fields;
            }

            $staticFieldRows.find('.ucg-static-field-row').each(function () {
                const $row = $(this);
                const key = String($row.data('key') || '');
                const targetField = String($row.attr('data-target-field') || '');
                const inputType = String($row.attr('data-input-type') || 'text');
                const enabled = $row.find('.ucg-static-field-enabled').is(':checked');
                const label = String($row.find('.ucg-static-field-label').first().text() || '').trim();
                const $input = $row.find('.ucg-static-field-value');
                let value = '';
                if (inputType === 'taxonomy_checklist') {
                    value = [];
                    $row.find('.ucg-static-field-value-check:checked').each(function () {
                        const selectedValue = String($(this).val() == null ? '' : $(this).val()).trim();
                        if (selectedValue && value.indexOf(selectedValue) === -1) {
                            value.push(selectedValue);
                        }
                    });
                } else if (inputType === 'multiselect') {
                    value = normalizeArrayFieldValue($input.val());
                } else {
                    value = String($input.val() == null ? '' : $input.val()).trim();
                }

                fields.push({
                    key: key,
                    label: label || key,
                    enabled: enabled,
                    target_field: targetField,
                    value: value
                });
            });

            return fields;
        }

        function hasEnabledStaticFields(fields) {
            if (!Array.isArray(fields)) {
                return false;
            }
            for (let i = 0; i < fields.length; i += 1) {
                const field = fields[i];
                if (!field || typeof field !== 'object' || !field.enabled) {
                    continue;
                }
                if (Array.isArray(field.value)) {
                    if (field.value.length > 0) {
                        return true;
                    }
                    continue;
                }
                if (String(field.value == null ? '' : field.value).trim() !== '') {
                    return true;
                }
            }
            return false;
        }

        function buildSeoAiFieldsForRun(seoTitlePrompt, seoDescriptionPrompt, fallbackLengthOptionId) {
            const source = Array.isArray(state.templateAiFields) ? state.templateAiFields : [];
            const fallbackLength = Number(fallbackLengthOptionId || 0);
            const fieldsByTarget = {
                title: null,
                description: null
            };

            source.forEach(function (field) {
                if (!field || typeof field !== 'object') {
                    return;
                }
                const target = String(field.target_field || '');
                if (target === 'seo_field:title' && !fieldsByTarget.title) {
                    fieldsByTarget.title = $.extend({}, field);
                } else if (target === 'seo_field:description' && !fieldsByTarget.description) {
                    fieldsByTarget.description = $.extend({}, field);
                }
            });

            if (!fieldsByTarget.title) {
                fieldsByTarget.title = {
                    key: 'seo_title',
                    label: jsT('SEO Title'),
                    target_field: 'seo_field:title',
                    length_option_id: fallbackLength > 0 ? fallbackLength : 0,
                    max_chars: 70
                };
            }
            if (!fieldsByTarget.description) {
                fieldsByTarget.description = {
                    key: 'seo_description',
                    label: jsT('SEO Description'),
                    target_field: 'seo_field:description',
                    length_option_id: fallbackLength > 0 ? fallbackLength : 0,
                    max_chars: 160
                };
            }

            fieldsByTarget.title.enabled = true;
            fieldsByTarget.title.prompt = String(seoTitlePrompt || '');
            fieldsByTarget.title.length_option_id = Number(fieldsByTarget.title.length_option_id || 0) > 0
                ? Number(fieldsByTarget.title.length_option_id || 0)
                : (fallbackLength > 0 ? fallbackLength : 0);
            fieldsByTarget.title.max_chars = Number(fieldsByTarget.title.max_chars || 0) > 0
                ? Number(fieldsByTarget.title.max_chars || 0)
                : 70;

            fieldsByTarget.description.enabled = true;
            fieldsByTarget.description.prompt = String(seoDescriptionPrompt || '');
            fieldsByTarget.description.length_option_id = Number(fieldsByTarget.description.length_option_id || 0) > 0
                ? Number(fieldsByTarget.description.length_option_id || 0)
                : (fallbackLength > 0 ? fallbackLength : 0);
            fieldsByTarget.description.max_chars = Number(fieldsByTarget.description.max_chars || 0) > 0
                ? Number(fieldsByTarget.description.max_chars || 0)
                : 160;

            return [fieldsByTarget.title, fieldsByTarget.description];
        }

        function activeTemplateInput() {
            if ($activeTemplateTextarea && $activeTemplateTextarea.length && $activeTemplateTextarea.is(':visible')) {
                return $activeTemplateTextarea;
            }
            const $multiPrompt = getAiRowsContainers().find('.ucg-ai-field-prompt:visible').first();
            if ($multiPrompt.length) {
                return $multiPrompt;
            }
            if ($templateBody.is(':visible')) {
                return $templateBody;
            }
            if ($templateBodySeoTitle.is(':visible')) {
                return $templateBodySeoTitle;
            }
            return $templateBody;
        }

        function updateScenarioTemplateInputs() {
            const scenario = getScenario();
            applyPostTypeSelectionVisibility(scenario);
            const isSeo = scenario === 'seo_tags';
            const isMulti = scenarioSupportsMultiFields(scenario);
            if ($templatePromptCard.length) {
                $templatePromptCard.toggle(!isMulti);
            }
            if ($templateTokenCard.length) {
                $templateTokenCard.toggle(!isMulti);
            }
            if ($templateBodyStandardWrap.length) {
                $templateBodyStandardWrap.toggle(!isSeo && !isMulti);
            }
            if ($templateBodySeoWrap.length) {
                $templateBodySeoWrap.prop('hidden', !isSeo).toggle(isSeo);
            }
            if ($templateBodyMultiWrap.length) {
                $templateBodyMultiWrap.prop('hidden', !isMulti).toggle(isMulti);
            }
            if (isSeo) {
                $activeTemplateTextarea = $templateBodySeoTitle;
            } else if (isMulti) {
                $activeTemplateTextarea = getAiRowsContainers().find('.ucg-ai-field-prompt').first();
            } else {
                $activeTemplateTextarea = $templateBody;
            }
            if ($lengthControlsWrap.length) {
                $lengthControlsWrap.toggle(!isSeo && !isMulti);
            }
            if ($seoGuidelines.length) {
                $seoGuidelines.prop('hidden', !isSeo).toggle(isSeo);
            }
            if (isMulti) {
                renderAiFieldRows(state.templateAiFields);
                renderStaticFieldRows(state.templateStaticFields);
                $activeTemplateTextarea = getAiRowsContainers().find('.ucg-ai-field-prompt').first();
            }
            applyStep2ModeVisibility();
            togglePublishDateRangeControls(scenario);
            applyItemsPerPostVisibility(scenario);
            toggleWooRatingRangeControls(scenario);
        }

        function applyStyleDefaultsFromSchema() {
            const settings = state.schema && state.schema.settings ? state.schema.settings : {};
            const langRaw = settings && settings.default_language ? String(settings.default_language) : 'auto';
            const toneRaw = settings && settings.default_tone ? String(settings.default_tone) : 'neutral';
            const language = (langRaw === 'auto' || langRaw === 'ru' || langRaw === 'en') ? langRaw : 'auto';
            const tone = (toneRaw === 'neutral' || toneRaw === 'official' || toneRaw === 'friendly') ? toneRaw : 'neutral';

            if ($styleLanguage.length) {
                setEnhancedSelectValue($styleLanguage, language);
            }
            if ($styleTone.length) {
                setEnhancedSelectValue($styleTone, tone);
            }
        }

        function normalizeToastType(type) {
            const normalized = String(type || '').toLowerCase();
            if (normalized === 'success' || normalized === 'error' || normalized === 'warning' || normalized === 'loading') {
                return normalized;
            }
            return 'info';
        }

        function defaultToastTtl(type) {
            return 5000;
        }

        function toastIcon(type) {
            const normalized = normalizeToastType(type);
            if (normalized === 'success') {
                return '✓';
            }
            if (normalized === 'error') {
                return '✕';
            }
            if (normalized === 'warning') {
                return '⚠';
            }
            if (normalized === 'loading') {
                return '⟳';
            }
            return 'ℹ';
        }

        function setToastBarState($bar, percent, durationMs) {
            if (!$bar || !$bar.length) {
                return;
            }
            const clamped = Math.max(0, Math.min(100, Number(percent || 0)));
            $bar.css({ transition: 'none', width: clamped + '%' });
            if (durationMs > 0) {
                void $bar.get(0).offsetWidth;
                $bar.css({ transition: 'width ' + durationMs + 'ms linear', width: '0%' });
            }
        }

        function applyToastView(item) {
            if (!item || !item.$el || !item.$el.length) {
                return;
            }
            const $el = item.$el;
            const typeClass = 'ucg-toast--' + item.type;
            $el
                .removeClass('ucg-toast--success ucg-toast--error ucg-toast--warning ucg-toast--info ucg-toast--loading ucg-toast--persistent is-leaving')
                .addClass(typeClass)
                .attr('role', item.type === 'error' ? 'alert' : 'status');
            if (item.ttl <= 0) {
                $el.addClass('ucg-toast--persistent');
            }
            $el.find('.ucg-toast__icon-glyph').text(toastIcon(item.type));
            $el.find('.ucg-toast__title').text(item.message);
            const $detail = $el.find('.ucg-toast__detail');
            if (item.detail) {
                $detail.text(item.detail).prop('hidden', false);
            } else {
                $detail.text('').prop('hidden', true);
            }
            $el.find('.ucg-toast__progress').prop('hidden', item.ttl <= 0);
        }

        function removeToastItem(item) {
            if (!item) {
                return;
            }
            if (item.timeoutId) {
                window.clearTimeout(item.timeoutId);
                item.timeoutId = 0;
            }
            if (item.key) {
                toastByKey.delete(item.key);
            }
            toastById.delete(item.id);
            if (item.$el && item.$el.length) {
                item.$el.remove();
            }
        }

        function closeToastById(id, immediate) {
            const key = String(id || '');
            if (!key || !toastById.has(key)) {
                return;
            }
            const item = toastById.get(key);
            if (!item) {
                return;
            }
            if (item.timeoutId) {
                window.clearTimeout(item.timeoutId);
                item.timeoutId = 0;
            }
            if (immediate) {
                removeToastItem(item);
                return;
            }
            if (!item.$el || !item.$el.length || item.$el.hasClass('is-leaving')) {
                return;
            }
            item.$el.addClass('is-leaving');
            window.setTimeout(function () {
                removeToastItem(item);
            }, 150);
        }

        function startToastTimer(item) {
            if (!item || item.ttl <= 0 || !item.$bar || !item.$bar.length) {
                return;
            }
            if (item.timeoutId) {
                window.clearTimeout(item.timeoutId);
                item.timeoutId = 0;
            }
            if (item.remainingMs <= 0) {
                closeToastById(item.id, false);
                return;
            }
            const percent = item.ttl > 0 ? ((item.remainingMs / item.ttl) * 100) : 0;
            if (toastTimersPaused) {
                setToastBarState(item.$bar, percent, 0);
                return;
            }
            item.timerStartedAt = Date.now();
            item.timeoutId = window.setTimeout(function () {
                closeToastById(item.id, false);
            }, item.remainingMs);
            setToastBarState(item.$bar, percent, item.remainingMs);
        }

        function pauseToastTimers() {
            if (toastTimersPaused) {
                return;
            }
            toastTimersPaused = true;
            toastById.forEach(function (item) {
                if (!item || item.ttl <= 0 || !item.timeoutId) {
                    return;
                }
                const elapsed = Date.now() - Number(item.timerStartedAt || 0);
                item.remainingMs = Math.max(0, item.remainingMs - Math.max(0, elapsed));
                window.clearTimeout(item.timeoutId);
                item.timeoutId = 0;
                const percent = item.ttl > 0 ? ((item.remainingMs / item.ttl) * 100) : 0;
                setToastBarState(item.$bar, percent, 0);
            });
        }

        function resumeToastTimers() {
            if (!toastTimersPaused) {
                return;
            }
            toastTimersPaused = false;
            toastById.forEach(function (item) {
                startToastTimer(item);
            });
        }

        function trimToastStack(preserveId) {
            const keepId = String(preserveId || '');
            while (toastById.size > 3) {
                let candidateId = '';
                $toastStack.children('.ucg-toast').each(function () {
                    if (candidateId) {
                        return;
                    }
                    const currentId = String($(this).attr('data-toast-id') || '');
                    if (!currentId || currentId === keepId) {
                        return;
                    }
                    candidateId = currentId;
                });
                if (!candidateId) {
                    break;
                }
                closeToastById(candidateId, true);
            }
        }

        function showRunToast(options) {
            if (!$toastStack.length) {
                return null;
            }

            const opts = options && typeof options === 'object' ? options : {};
            const message = String(opts.message || '').trim();
            const detail = String(opts.detail || '').trim();
            if (!message) {
                return null;
            }

            const type = normalizeToastType(opts.type || 'info');
            const key = opts.key ? String(opts.key) : '';
            const now = Date.now();
            const signature = type + ':' + message + '|' + detail + (key ? ('|' + key) : '');
            if (!opts.force && !key && signature === lastToastSignature && (now - lastToastAt) < 1200) {
                return null;
            }
            lastToastSignature = signature;
            lastToastAt = now;

            const ttl = typeof opts.ttl === 'number' ? Math.max(0, Number(opts.ttl)) : defaultToastTtl(type);
            const baseItem = {
                type: type,
                message: message,
                detail: detail,
                ttl: ttl,
                remainingMs: ttl,
                timerStartedAt: 0
            };

            if (key && toastByKey.has(key)) {
                const existing = toastByKey.get(key);
                if (existing) {
                    if (existing.timeoutId) {
                        window.clearTimeout(existing.timeoutId);
                        existing.timeoutId = 0;
                    }
                    existing.type = baseItem.type;
                    existing.message = baseItem.message;
                    existing.detail = baseItem.detail;
                    existing.ttl = baseItem.ttl;
                    existing.remainingMs = baseItem.remainingMs;
                    existing.timerStartedAt = 0;
                    applyToastView(existing);
                    startToastTimer(existing);
                    return existing;
                }
            }

            const id = String(++toastSeq);
            const closeLabel = escapeHtml(jsT('Закрыть уведомление'));
            const $toast = $(
                '<article class="ucg-toast" data-toast-id="' + id + '" role="status">' +
                '<div class="ucg-toast__icon" aria-hidden="true"><span class="ucg-toast__icon-glyph"></span></div>' +
                '<div class="ucg-toast__body"><div class="ucg-toast__title"></div><div class="ucg-toast__detail" hidden></div></div>' +
                '<button type="button" class="ucg-toast__close" aria-label="' + closeLabel + '"><span aria-hidden="true">×</span></button>' +
                '<div class="ucg-toast__progress"><span class="ucg-toast__bar"></span></div>' +
                '</article>'
            );
            $toastStack.append($toast);

            const item = {
                id: id,
                key: key,
                type: baseItem.type,
                message: baseItem.message,
                detail: baseItem.detail,
                ttl: baseItem.ttl,
                remainingMs: baseItem.remainingMs,
                timerStartedAt: 0,
                timeoutId: 0,
                $el: $toast,
                $bar: $toast.find('.ucg-toast__bar')
            };
            toastById.set(id, item);
            if (key) {
                toastByKey.set(key, item);
            }
            applyToastView(item);
            trimToastStack(id);
            startToastTimer(item);
            return item;
        }

        function setRunStatus(message, isError, toastOptions) {
            const text = String(message || '').trim();
            if ($runResult.length) {
                $runResult.text(text);
            }
            if (!text) {
                return;
            }
            const options = toastOptions && typeof toastOptions === 'object' ? toastOptions : {};
            if (options.skipToast) {
                return;
            }
            const hasExplicitToast = !!(options.type || options.key || options.detail || options.force || typeof options.ttl === 'number');
            if (!hasExplicitToast && !isError) {
                return;
            }
            showRunToast({
                message: text,
                detail: options.detail || '',
                type: options.type || (isError ? 'error' : 'info'),
                key: options.key || '',
                ttl: options.ttl,
                force: !!options.force
            });
        }

        function clearRunMonitorTimer() {
            if (state.runMonitorTimer) {
                window.clearTimeout(state.runMonitorTimer);
                state.runMonitorTimer = null;
            }
        }

        function resetRunMonitor() {
            clearRunMonitorTimer();
            state.activeRunId = 0;
            if (!$runMonitor.length) {
                return;
            }
            $runMonitor.prop('hidden', true);
            $runReviewLink.hide();
            $runProgressBar.css('width', '0%');
            $runMonitorStats.text('0%');
            $runLog.html(jsT('<div class="ucg-muted">Ожидаем первые события...</div>'));
        }

        function setRunChipStatus(status, label) {
            if (!$runMonitorStatus.length) {
                return;
            }

            const normalized = String(status || '');
            const statusClassMap = {
                queued: 'ucg-status-queued',
                running: 'ucg-status-running',
                completed: 'ucg-status-completed',
                failed: 'ucg-status-failed'
            };

            $runMonitorStatus
                .removeClass('ucg-status-queued ucg-status-running ucg-status-completed ucg-status-failed')
                .addClass(statusClassMap[normalized] || 'ucg-status-running')
                .text(label || normalized || jsT('—'));
        }

        function renderRunLog(logs) {
            if (!$runLog.length) {
                return;
            }

            if (!Array.isArray(logs) || !logs.length) {
                $runLog.html(jsT('<div class="ucg-muted">События появятся после первых обработанных записей.</div>'));
                return;
            }

            function formatItemStatusLabel(log) {
                const normalized = String(log && log.status ? log.status : '').trim().toLowerCase();
                const fallbackLabel = log && log.status_label ? String(log.status_label).trim() : '';
                if (normalized === 'approved') {
                    return jsT('Применено');
                }
                if (normalized === 'generated') {
                    return jsT('Сгенерировано (ожидает проверки)');
                }
                if (normalized === 'failed') {
                    return jsT('Ошибка');
                }
                if (normalized === 'queued') {
                    return jsT('В очереди');
                }
                if (normalized === 'running') {
                    return jsT('Обрабатывается');
                }
                const fallbackNormalized = fallbackLabel.toLowerCase();
                if (fallbackNormalized === jsT('Одобрено').toLowerCase() || fallbackNormalized === 'approved') {
                    return jsT('Применено');
                }
                return fallbackLabel || jsT('Статус');
            }

            const lines = logs.map(function (log) {
                const postId = Number(log && log.post_id ? log.post_id : 0);
                const statusLabel = formatItemStatusLabel(log);
                const error = log && log.error_message ? String(log.error_message) : '';
                const timestamp = log && log.updated_at ? String(log.updated_at) : '';
                let text = timestamp ? ('[' + timestamp + '] ') : '';
                text += jsT('Запись #') + postId + ': ' + statusLabel;
                if (error) {
                    text += jsT(' — ') + error;
                }
                return '<li>' + escapeHtml(text) + '</li>';
            }).join('');

            $runLog.html('<ul class="ucg-run-log-list">' + lines + '</ul>');
        }

        function renderRunState(data) {
            if (!$runMonitor.length || !data || typeof data !== 'object') {
                return;
            }

            const run = data.run || {};
            const runId = Number(run.id || state.activeRunId || 0);
            const progress = Math.max(0, Math.min(100, Number(run.progress || 0)));
            const total = Number(run.total_items || 0);
            const processed = Number(run.processed_items || 0);
            const queued = Number(run.queued_items || 0);
            const success = Number(run.success_items || 0);
            const failed = Number(run.failed_items || 0);

            if (runId > 0) {
                $runMonitorTitle.text(jsT('Запуск #') + runId);
            }
            setRunChipStatus(run.status, run.status_label);
            $runProgressBar.css('width', progress + '%');
            $runMonitorStats.text(progress + jsT('% • обработано ') + processed + jsT(' из ') + total + jsT(' • в очереди ') + queued + jsT(' • ошибок ') + failed + jsT(' • готово ') + success);
            renderRunLog(Array.isArray(data.logs) ? data.logs : []);

            if (data.review_url) {
                $runReviewLink.attr('href', String(data.review_url)).show();
            }
        }

        function pollRunState() {
            if (!state.activeRunId) {
                return;
            }

            $.post(ucgAdmin.ajaxUrl, {
                action: 'ucg_run_status',
                nonce: ucgAdmin.nonce,
                run_id: state.activeRunId
            }).done(function (response) {
                if (!response.success) {
                    const msg = response.data && response.data.message ? response.data.message : jsT('Не удалось получить прогресс запуска.');
                    setRunStatus(msg, true, { type: 'error', key: 'run-monitor', force: true });
                    clearRunMonitorTimer();
                    return;
                }

                const data = response.data || {};
                renderRunState(data);

                if (data.is_finished) {
                    const run = data.run || {};
                    const doneCount = Number(run.success_items || 0);
                    const doneDetail = jsT('Готово записей: ') + doneCount + '.';
                    setRunStatus(jsT('Генерация завершена.'), false, { type: 'success', key: 'run-monitor', detail: doneDetail, force: true });
                    clearRunMonitorTimer();
                    return;
                }

                const run = data.run || {};
                const runStatus = String(run.status || '');
                const processed = Number(run.processed_items || 0);
                const nextPollDelay = (runStatus === 'queued' || processed <= 0) ? 1000 : 3000;

                clearRunMonitorTimer();
                state.runMonitorTimer = window.setTimeout(pollRunState, nextPollDelay);
            }).fail(function () {
                setRunStatus(jsT('AJAX ошибка при обновлении прогресса.'), true, { type: 'error', key: 'run-monitor', force: true });
                clearRunMonitorTimer();
                state.runMonitorTimer = window.setTimeout(pollRunState, 5000);
            });
        }

        function startRunMonitor(runId, queued) {
            const numericRunId = Number(runId || 0);
            if (!numericRunId || !$runMonitor.length) {
                return;
            }

            state.activeRunId = numericRunId;
            clearRunMonitorTimer();

            $runMonitor.prop('hidden', false);
            $runReviewLink.hide();
            $runMonitorTitle.text(jsT('Запуск #') + numericRunId);
            setRunChipStatus('queued', jsT('В очереди'));
            $runProgressBar.css('width', '0%');
            $runMonitorStats.text(jsT('0% • в очереди ') + Number(queued || 0));
            $runLog.html(jsT('<div class="ucg-muted">Запуск создан. Ждём первые ответы от API...</div>'));
            setRunStatus(jsT('Генерация запущена...'), false, {
                type: 'loading',
                key: 'run-monitor',
                detail: jsT('В очереди: ') + Number(queued || 0),
                force: true
            });

            pollRunState();
        }

        function switchStep(step) {
            step = Number(step || 1);
            if (step < 1) {
                step = 1;
            }
            if (step > 3) {
                step = 3;
            }
            state.step = step;

            $('.ucg-stepper__item').removeClass('is-active is-done');
            $('.ucg-stepper__item[data-step-target="' + step + '"]').addClass('is-active');
            for (var _i = 1; _i < step; _i++) {
                $('.ucg-stepper__item[data-step-target="' + _i + '"]').addClass('is-done');
            }

            $('.ucg-step-panel').removeClass('is-active');
            $('.ucg-step-panel[data-step="' + step + '"]').addClass('is-active');

            if (step === 3) {
                renderRunSummary();
                setRunStatus('', false);
            } else {
                cancelQuoteRefreshRequest();
            }

            if (step === 2) {
                applyStep2ModeVisibility();
                if (!isCreateNewMode() && state.total === 0 && normalizeFilters().length === 0) {
                    previewPosts(1, $('#ucg-preview-posts'));
                }
            }
        }

        function updateSelectedCount() {
            const selectedCount = Number(state.selectedIds.size || 0);
            $selectedCount.text(String(selectedCount));
        }

        function normalizeFilters() {
            const filters = [];
            $filterRows.find('.ucg-filter-row-item').each(function () {
                const $row = $(this);
                const field = String($row.find('.ucg-filter-field').val() || '');
                const operator = String($row.find('.ucg-filter-operator').val() || '');
                const value = String($row.find('.ucg-filter-value').val() || '').trim();

                if (!field || !operator) {
                    return;
                }

                if (operator !== 'is_empty' && operator !== 'not_empty' && !value) {
                    return;
                }

                filters.push({ field: field, operator: operator, value: value });
            });
            return filters;
        }

        function renderTargetFields() {
            const fields = Array.isArray(state.schema.target_fields) ? state.schema.target_fields : [];
            const fallbackFields = [
                { value: 'post:post_content', label: jsT('Содержание (post_content)') },
                { value: 'post:post_title', label: jsT('Заголовок (post_title)') },
                { value: 'post:post_excerpt', label: jsT('Краткое описание (post_excerpt)') }
            ];
            const sourceFields = fields.length ? fields : fallbackFields;
            const currentValue = getEnhancedSelectValue($targetField);
            if ($targetFieldLabel.length) {
                const targetLabel = state.schema && state.schema.target_field_label
                    ? String(state.schema.target_field_label)
                    : jsT('Целевое поле');
                $targetFieldLabel.text(targetLabel);
            }
            const schemaScenario = state.schema ? String(state.schema.scenario || '') : '';
            let emptyOptionLabel = jsT('Выберите поле');
            if (schemaScenario === 'seo_tags') {
                emptyOptionLabel = jsT('Выберите SEO-плагин');
            } else if (scenarioSupportsMultiFields(schemaScenario)) {
                emptyOptionLabel = jsT('Авто (по выбранным полям)');
            }
            let html = '<option value="">' + escapeHtml(emptyOptionLabel) + '</option>';
            sourceFields.forEach(function (field) {
                const value = field && field.value ? String(field.value) : '';
                if (!value) {
                    return;
                }
                const label = field && field.label ? String(field.label) : value;
                const disabled = !!(field && field.disabled);
                html += '<option value="' + escapeHtml(value) + '"' + (disabled ? ' disabled' : '') + '>' + escapeHtml(label) + '</option>';
            });
            destroyEnhancedSelectInstance($targetField);
            $targetField.html(html);
            if (currentValue) {
                $targetField.val(currentValue);
            }
            if (!$targetField.val() && sourceFields.length) {
                const first = sourceFields[0] && sourceFields[0].value ? String(sourceFields[0].value) : '';
                if (first) {
                    $targetField.val(first);
                }
            }
            initEnhancedSelects($targetField);
            updateScenarioTemplateInputs();
        }

        function renderTemplates() {
            const templates = Array.isArray(state.schema.templates) ? state.schema.templates : [];
            const currentTemplateValue = getEnhancedSelectValue($templateSelect);
            const sourceTemplates = templates;
            let html = jsT('<option value="">Не выбрано</option>');
            let defaultTemplateId = 0;
            sourceTemplates.forEach(function (template) {
                const id = Number(template && template.id ? template.id : 0);
                if (!id) {
                    return;
                }
                const name = template && template.name ? String(template.name) : ('#' + id);
                const templatePostType = template && template.post_type ? String(template.post_type) : '';
                const typeSuffix = templatePostType ? (' (' + templatePostType + ')') : '';
                html += '<option value="' + id + '">#' + id + jsT(' — ') + escapeHtml(name) + escapeHtml(typeSuffix) + '</option>';
                if (!defaultTemplateId && template && template.is_default) {
                    defaultTemplateId = id;
                }
            });
            destroyEnhancedSelectInstance($templateSelect);
            $templateSelect.html(html);
            if (defaultTemplateId > 0) {
                $templateSelect.val(String(defaultTemplateId));
            } else if (currentTemplateValue !== '' && $templateSelect.find('option[value="' + currentTemplateValue + '"]').length) {
                $templateSelect.val(currentTemplateValue);
            } else {
                $templateSelect.val('');
            }
            $templateBody.val('');
            $templateBodySeoTitle.val('');
            $templateBodySeoDescription.val('');
            state.templateAiFields = [];
            state.templateStaticFields = [];
            $templateName.val('');
            $saveTemplateChanges.prop('checked', false);
            updateTemplateMode();
            updateScenarioTemplateInputs();
            resetRunMonitor();
            initEnhancedSelects($templateSelect);
            const activeTemplateId = Number($templateSelect.val() || 0);
            if (activeTemplateId > 0) {
                loadTemplate(activeTemplateId);
            }
        }

        function renderTextLengthOptions() {
            const options = Array.isArray(state.schema.text_length_options) ? state.schema.text_length_options : [];
            const fallbackOptions = [
                { id: 1, name: jsT('Короткое'), max_chars: 500, credits_cost: 1 },
                { id: 2, name: jsT('Стандартное'), max_chars: 1500, credits_cost: 3 },
                { id: 3, name: jsT('Расширенное'), max_chars: 3000, credits_cost: 6 },
                { id: 4, name: jsT('Большое'), max_chars: 5000, credits_cost: 10 }
            ];
            const sourceOptions = options.length ? options : fallbackOptions;
            let html = '';
            let defaultId = Number(state.schema.default_length_option_id || 0);

            sourceOptions.forEach(function (option) {
                const id = Number(option && option.id ? option.id : 0);
                if (!id) {
                    return;
                }
                const name = option && option.name ? String(option.name) : ('#' + id);
                const maxChars = Number(option && option.max_chars ? option.max_chars : 0);
                const credits = Number(option && option.credits_cost ? option.credits_cost : 0);
                const label = name + jsT(' — до ') + maxChars + jsT(' символов / ') + formatCreditsValue(credits, 2) + jsT(' кр.');
                html += '<option value="' + id + '">' + escapeHtml(label) + '</option>';
            });

            if (!html) {
                html = jsT('<option value="">Нет доступных диапазонов</option>');
                defaultId = 0;
            } else if (defaultId <= 0) {
                const first = sourceOptions[0];
                defaultId = Number(first && first.id ? first.id : 0);
            }

            destroyEnhancedSelectInstance($lengthOption);
            $lengthOption.html(html);
            if (defaultId > 0) {
                $lengthOption.val(String(defaultId));
            }
            initEnhancedSelects($lengthOption);

            const hintText = state.schema.vary_length_hint ? String(state.schema.vary_length_hint) : '';
            $varyLengthHint.text(hintText);
            if ($varyLengthHelp.length) {
                $varyLengthHelp.attr('data-tip', hintText);
            }
        }

        function estimateCreditsByLength(modelItem, lengthOptionId) {
            if (!modelItem || typeof modelItem !== 'object') {
                return 0;
            }
            const map = modelItem.estimated_credits_by_length && typeof modelItem.estimated_credits_by_length === 'object'
                ? modelItem.estimated_credits_by_length
                : {};

            const key = String(Number(lengthOptionId || 0));
            if (Object.prototype.hasOwnProperty.call(map, key)) {
                return Number(map[key] || 0);
            }

            const keys = Object.keys(map);
            if (!keys.length) {
                return 0;
            }
            const first = keys[0];
            return Number(map[first] || 0);
        }

        function findImageModelById(modelId) {
            const normalizedId = String(modelId || 'auto').trim() || 'auto';
            const models = imageGenerationModelsSource();
            for (let i = 0; i < models.length; i += 1) {
                const item = models[i];
                if (!item || typeof item !== 'object') {
                    continue;
                }
                if (String(item.id || '') === normalizedId) {
                    return item;
                }
            }
            for (let j = 0; j < models.length; j += 1) {
                const fallback = models[j];
                if (!fallback || typeof fallback !== 'object') {
                    continue;
                }
                if (String(fallback.id || '') === 'auto') {
                    return fallback;
                }
            }
            return models.length ? models[0] : null;
        }

        function estimateImageFieldCredits(field) {
            const model = findImageModelById(field && field.model ? field.model : 'auto');
            const multiplier = Number(model && model.multiplier ? model.multiplier : 1);
            const safeMultiplier = Number.isFinite(multiplier) && multiplier > 0 ? multiplier : 1;
            const imagesCountRaw = Number(field && field.images_count ? field.images_count : 1);
            const imagesCount = Number.isFinite(imagesCountRaw) ? Math.max(1, Math.min(8, Math.round(imagesCountRaw))) : 1;
            return imagesCount * safeMultiplier;
        }

        function normalizeModelModalities(value, fallback) {
            const fallbackValues = Array.isArray(fallback) ? fallback : [];
            const source = Array.isArray(value)
                ? value
                : (typeof value === 'string' ? String(value).split(',') : []);
            const normalized = [];
            const seen = new Set();
            source.forEach(function (item) {
                const token = String(item || '').trim().toLowerCase().replace(/[^a-z0-9_\-+]/g, '');
                if (!token || seen.has(token)) {
                    return;
                }
                seen.add(token);
                normalized.push(token);
            });
            if (normalized.length) {
                return normalized;
            }
            return fallbackValues.slice();
        }

        function isTextToTextModelModality(modelItem) {
            if (!modelItem || typeof modelItem !== 'object') {
                return true;
            }
            const inputModalities = normalizeModelModalities(modelItem.input_modalities, ['text']);
            const outputModalities = normalizeModelModalities(modelItem.output_modalities, ['text']);
            return inputModalities.length === 1 &&
                outputModalities.length === 1 &&
                inputModalities[0] === 'text' &&
                outputModalities[0] === 'text';
        }

        function modelArchitectureLabel(modelItem) {
            if (!modelItem || typeof modelItem !== 'object') {
                return '';
            }
            const raw = modelItem.architecture_modality ? String(modelItem.architecture_modality).trim() : '';
            if (!raw) {
                return '';
            }
            const normalizedRaw = raw.toLowerCase().replace(/\s+/g, '');
            if (normalizedRaw === 'text->text' || normalizedRaw === 'text-to-text') {
                return '';
            }
            if (isTextToTextModelModality(modelItem)) {
                return '';
            }
            return raw;
        }

        function generationModelsForScenario(scenario) {
            const models = Array.isArray(state.schema.generation_models) ? state.schema.generation_models : [];
            return models.filter(function (item) {
                if (!item || typeof item !== 'object') {
                    return false;
                }
                const id = String(item.id || '').trim();
                const name = String(item.name || id).trim();
                return id !== '' && name !== '';
            });
        }

        function modelsForScenario(scenario) {
            const normalized = String(scenario || '').trim().toLowerCase();
            if (normalized === 'image_generation') {
                return imageGenerationModelsSource();
            }
            return generationModelsForScenario(normalized);
        }

        function activeModelItem() {
            const selectedModelId = getEnhancedSelectValue($modelSelect) || String(state.defaultModel || 'auto');
            const models = modelsForScenario(getScenario());
            for (let i = 0; i < models.length; i += 1) {
                const item = models[i];
                if (!item || typeof item !== 'object') {
                    continue;
                }
                if (String(item.id || '') === selectedModelId) {
                    return item;
                }
            }
            return null;
        }

        function updateModelHint() {
            renderRunSummary();
        }

        function renderRunSummary() {
            if (!$runSummary.length) {
                return;
            }

            const scenario = getScenario();
            const planned = getPlannedCount();
            const model = activeModelItem();
            let unitsPerRecord = scenarioSupportsItemsPerPost(scenario) ? normalizeItemsPerPost($itemsPerPost.val()) : 1;
            let creditsPerUnit = 0;
            let totalCredits = 0;
            let unitCountLabel = scenario === 'seo_tags' ? jsT('Пакетов') : jsT('Полей');
            let perUnitLabel = scenario === 'seo_tags'
                ? jsT('Цена за пакет')
                : jsT('Цена за поле');
            let formulaText = '';
            const quoteContext = state.step === 3 ? buildRunQuoteRequestContext() : null;
            const quoteSignature = quoteContext && quoteContext.signature ? String(quoteContext.signature) : '';
            const quoteData = state.quoteEstimate && state.quoteEstimate.signature === quoteSignature
                ? state.quoteEstimate.data
                : null;
            const quoteEstimate = quoteData && quoteData.estimate && typeof quoteData.estimate === 'object'
                ? quoteData.estimate
                : null;
            const hasQuoteEstimate = !!quoteEstimate;
            let quoteTotalP90 = 0;

            if (scenarioSupportsMultiFields(scenario)) {
                const hasRenderedAiRows = getAiRowsContainers().length && getAiRowsContainers().find('.ucg-ai-field-row').length > 0;
                let aiFields = collectAiFieldsForRun().filter(function (field) {
                    return !!(field && field.enabled && String(field.prompt || '').trim() !== '');
                });
                if (!hasRenderedAiRows && !aiFields.length) {
                    aiFields = normalizeTemplateAiFields(state.templateAiFields).filter(function (field) {
                        return !!(field && field.enabled && String(field.prompt || '').trim() !== '');
                    });
                }
                unitsPerRecord = aiFields.length;
                let perRecordCredits = 0;
                const fieldCostParts = [];
                aiFields.forEach(function (field) {
                    const outputType = normalizeAiOutputType(field && field.target_field ? field.target_field : '', field && field.key ? field.key : '', field && field.output_type ? field.output_type : '');
                    let fieldCredits = 0;
                    if (outputType === 'image') {
                        fieldCredits = estimateImageFieldCredits(field);
                    } else {
                        fieldCredits = estimateCreditsByLength(model, Number(field.length_option_id || 0));
                    }
                    perRecordCredits += fieldCredits;
                    const fieldLabel = String(field.label || field.key || jsT('Поле'));
                    fieldCostParts.push(fieldLabel + ': ~' + formatCreditsValue(fieldCredits, 2) + ' ' + jsT('кр.'));
                });
                creditsPerUnit = perRecordCredits;
                totalCredits = planned > 0 ? (planned * perRecordCredits) : 0;
                unitCountLabel = jsT('AI-полей/запись');
                perUnitLabel = jsT('Цена за запись');
                const recordWord = pluralRu(planned, [jsT('запись'), jsT('записи'), jsT('записей')]);
                formulaText = String(planned) + ' ' + recordWord + ' × ~' + formatCreditsValue(perRecordCredits, 2) + ' ' + jsT('кр.') +
                    ' = ~' + formatCreditsValue(totalCredits, 2) + ' ' + jsT('кр.');
                if (fieldCostParts.length) {
                    formulaText += '\n' + fieldCostParts.join(' + ');
                }
            } else {
                const plannedUnits = planned > 0 ? (planned * unitsPerRecord) : 0;
                const lengthOptionId = Number($lengthOption.val() || 0);
                creditsPerUnit = estimateCreditsByLength(model, lengthOptionId);
                totalCredits = plannedUnits > 0 ? (plannedUnits * creditsPerUnit) : 0;
                const recordWord = pluralRu(planned, [jsT('запись'), jsT('записи'), jsT('записей')]);
                const unitForms = scenario === 'seo_tags'
                    ? [jsT('пакет'), jsT('пакета'), jsT('пакетов')]
                    : [jsT('поле'), jsT('поля'), jsT('полей')];
                const unitWord = pluralRu(unitsPerRecord, unitForms);
                formulaText = String(planned) + ' ' + recordWord + ' × ' + String(unitsPerRecord) + ' ' + unitWord + ' × ~' +
                    formatCreditsValue(creditsPerUnit, 2) + ' ' + jsT('кр.') + ' = ~' + formatCreditsValue(totalCredits, 2) + ' ' + jsT('кр.');
            }

            if (hasQuoteEstimate) {
                const quotePerUnitP50 = Number(quoteEstimate.credits_per_unit_p50 || 0);
                const quotePerUnitP90 = Number(quoteEstimate.credits_per_unit_p90 || 0);
                const quotePerRecordP50 = Number(quoteEstimate.credits_per_record_p50 || 0);
                const quotePerRecordP90 = Number(quoteEstimate.credits_per_record_p90 || 0);
                const quoteTotalP50 = Number(quoteEstimate.total_credits_p50 || 0);
                const quoteTotalP90Value = Number(quoteEstimate.total_credits_p90 || 0);

                creditsPerUnit = scenarioSupportsMultiFields(scenario) ? quotePerRecordP50 : quotePerUnitP50;
                totalCredits = quoteTotalP50;
                quoteTotalP90 = Math.max(quoteTotalP50, quoteTotalP90Value);

                const recordWord = pluralRu(planned, [jsT('запись'), jsT('записи'), jsT('записей')]);
                if (scenarioSupportsMultiFields(scenario)) {
                    formulaText = String(planned) + ' ' + recordWord + ' × ~' +
                        formatCreditsValue(quotePerRecordP50, 2) + ' ' + jsT('кр.') + ' = ~' + formatCreditsValue(quoteTotalP50, 2) + ' ' + jsT('кр.');
                } else {
                    const unitForms = scenario === 'seo_tags'
                        ? [jsT('пакет'), jsT('пакета'), jsT('пакетов')]
                        : [jsT('поле'), jsT('поля'), jsT('полей')];
                    const unitWord = pluralRu(unitsPerRecord, unitForms);
                    formulaText = String(planned) + ' ' + recordWord + ' × ' + String(unitsPerRecord) + ' ' + unitWord + ' × ~' +
                        formatCreditsValue(quotePerUnitP50, 2) + ' ' + jsT('кр.') + ' = ~' + formatCreditsValue(quoteTotalP50, 2) + ' ' + jsT('кр.');
                }
                if (quoteTotalP90 > quoteTotalP50) {
                    formulaText += '\n' + jsT('Верхняя оценка: ~') + formatCreditsValue(quoteTotalP90, 2) + ' ' + jsT('кр.');
                }
            }
            const modelName = model && model.name ? String(model.name) : jsT('По умолчанию');
            const provider = model && model.provider ? String(model.provider) : '';
            const resolved = model && model.resolved_model ? String(model.resolved_model) : '';

            let modelBase = modelName || provider || jsT('По умолчанию');
            const providerSuffix = provider ? (' (' + provider + ')') : '';
            if (providerSuffix && modelBase.toLowerCase().endsWith(providerSuffix.toLowerCase())) {
                modelBase = modelBase.slice(0, -providerSuffix.length).trim();
            }

            let modelDetails = modelBase;
            if (resolved && modelDetails.toLowerCase().indexOf(resolved.toLowerCase()) === -1) {
                modelDetails += ' (' + resolved + ')';
            }

            const modelRowValue = '' +
                '<span class="ucg-run-summary__model-text">' + escapeHtml(modelDetails) + '</span>' +
                '<button type="button" class="ucg-run-summary__model-change" id="ucg-run-summary-change-model">' + escapeHtml(jsT('Сменить ↗')) + '</button>';

            let perUnitDisplay = '~' + formatCreditsValue(creditsPerUnit, 2) + ' ' + jsT('кр.');
            if (hasQuoteEstimate) {
                const quotePerUnitP90 = scenarioSupportsMultiFields(scenario)
                    ? Number(quoteEstimate.credits_per_record_p90 || 0)
                    : Number(quoteEstimate.credits_per_unit_p90 || 0);
                if (quotePerUnitP90 > creditsPerUnit) {
                    perUnitDisplay += ' (' + jsT('до ~') + formatCreditsValue(quotePerUnitP90, 2) + ' ' + jsT('кр.') + ')';
                }
            }

            let rowsHtml = '' +
                '<li class="ucg-run-summary__row ucg-run-summary__row--model"><span>' + escapeHtml(jsT('Модель')) + '</span><strong class="ucg-run-summary__model-value">' + modelRowValue + '</strong></li>' +
                '<li class="ucg-run-summary__row"><span>' + escapeHtml(jsT('Записей')) + '</span><strong>' + escapeHtml(String(planned)) + '</strong></li>' +
                '<li class="ucg-run-summary__row"><span>' + escapeHtml(unitCountLabel) + '</span><strong>' + escapeHtml(String(unitsPerRecord)) + '</strong></li>' +
                '<li class="ucg-run-summary__row"><span>' + escapeHtml(perUnitLabel) + '</span><strong>' + escapeHtml(perUnitDisplay) + '</strong></li>';

            let totalDisplay = '~' + formatCreditsValue(totalCredits, 2) + ' ' + jsT('кр.');
            if (hasQuoteEstimate && quoteTotalP90 > totalCredits) {
                totalDisplay += ' (' + jsT('до ~') + formatCreditsValue(quoteTotalP90, 2) + ' ' + jsT('кр.') + ')';
            }

            let html = '' +
                '<div class="ucg-run-summary__top">' +
                '<h3 class="ucg-run-summary__title">' + escapeHtml(jsT('Сводка запуска')) + '</h3>' +
                '</div>' +
                '<ul class="ucg-run-summary__list">' + rowsHtml + '</ul>' +
                '<div class="ucg-run-summary__total">' +
                '<span class="ucg-run-summary__total-label">' +
                escapeHtml(jsT('Стоимость генерации')) +
                '<button type="button" class="ucg-help-tip ucg-help-tip--bottom ucg-run-summary__formula-tip" aria-label="' + escapeHtml(jsT('Формула стоимости генерации')) + '" data-tip="' + escapeHtml(formulaText) + '">' +
                '<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>' +
                '</button>' +
                '</span>' +
                '<strong>' + escapeHtml(totalDisplay) + '</strong>' +
                '</div>';

            if (planned <= 0) {
                html += '<p class="ucg-run-summary__note">' + escapeHtml(jsT('Нет записей для запуска.')) + '</p>';
            } else if (hasQuoteEstimate) {
                html += '<p class="ucg-run-summary__note">' + escapeHtml(jsT('Показываем базовую оценку и верхнюю границу (безопасный максимум).')) + '</p>';
            } else if (state.quoteRequestInFlight && state.quoteRequestSignature === quoteSignature) {
                html += '<p class="ucg-run-summary__note">' + escapeHtml(jsT('Уточняем стоимость через API…')) + '</p>';
            } else if (state.quoteError && state.quoteFailedSignature === quoteSignature) {
                html += '<p class="ucg-run-summary__note">' + escapeHtml(jsT('Показана локальная оценка.')) + ' ' + escapeHtml(state.quoteError) + '</p>';
            }

            $runSummary.html(html);
            if ($step3Total.length) {
                $step3Total.html(
                    escapeHtml(jsT('Итого: ')) +
                    '<strong>' + escapeHtml(totalDisplay) + '</strong>'
                );
            }
            scheduleRunQuoteRefresh();
        }

        function renderGenerationModels() {
            const scenario = getScenario();
            const models = modelsForScenario(scenario);
            const currentModel = getEnhancedSelectValue($modelSelect) || String(state.defaultModel || 'auto');
            let html = '';
            let selectedExists = false;
            let defaultModel = String(state.schema.default_model || state.defaultModel || 'auto');
            if (!defaultModel) {
                defaultModel = 'auto';
            }
            state.defaultModel = defaultModel;

            if (!models.length) {
                html = '<option value="auto">' + escapeHtml(jsT('Нет подходящих моделей (авто)')) + '</option>';
                selectedExists = true;
            } else {
                models.forEach(function (item) {
                    if (!item || typeof item !== 'object') {
                        return;
                    }
                    const id = item.id ? String(item.id) : '';
                    const name = item.name ? String(item.name) : id;
                    if (!id || !name) {
                        return;
                    }
                    const label = name;
                    html += '<option value="' + escapeHtml(id) + '">' + escapeHtml(label) + '</option>';
                    if (id === currentModel) {
                        selectedExists = true;
                    }
                });
            }

            destroyEnhancedSelectInstance($modelSelect);
            $modelSelect.html(html);
            const nextModel = selectedExists ? currentModel : defaultModel;
            initEnhancedSelects($modelSelect);
            $modelSelect.val(String(nextModel));
            const modelElement = $modelSelect.get(0);
            if (modelElement && modelElement.tomselect) {
                modelElement.tomselect.setValue(String(nextModel), true);
            }
            updateModelHint();
        }

        function tokenGroupKeyByToken(token) {
            const normalized = String(token || '').toLowerCase();
            if (normalized.indexOf('{tax:') === 0) {
                return 'taxonomy';
            }
            if (normalized.indexOf('{meta:') === 0 || normalized.indexOf('{acf:') === 0) {
                return 'meta';
            }
            return 'main';
        }

        function tokenGroupTitle(groupKey) {
            if (groupKey === 'taxonomy') {
                return jsT('Таксономии');
            }
            if (groupKey === 'meta') {
                return jsT('Meta-поля');
            }
            return jsT('Основные');
        }

        function compactTokenText(token) {
            const raw = String(token || '');
            if (raw.length <= 32) {
                return raw;
            }
            return raw.slice(0, 14) + '…' + raw.slice(-14);
        }

        function renderWizardTokens() {
            if (!$tokens.length) {
                return;
            }

            const sourceTokens = Array.isArray(state.schema.tokens) ? state.schema.tokens : [];
            const query = String($wizardTokenSearch.val() || '').trim().toLowerCase();
            const groups = {
                main: [],
                taxonomy: [],
                meta: []
            };

            sourceTokens.forEach(function (item) {
                const token = item && item.token ? String(item.token) : '';
                const label = item && item.label ? String(item.label) : token;
                if (!token) {
                    return;
                }

                if (query) {
                    const haystack = (token + ' ' + label).toLowerCase();
                    if (haystack.indexOf(query) === -1) {
                        return;
                    }
                }

                const groupKey = tokenGroupKeyByToken(token);
                if (!Object.prototype.hasOwnProperty.call(groups, groupKey)) {
                    groups.main.push({ token: token, label: label });
                    return;
                }
                groups[groupKey].push({ token: token, label: label });
            });

            const orderedGroups = ['main', 'taxonomy', 'meta'];
            let html = '';
            let totalVisible = 0;

            orderedGroups.forEach(function (groupKey) {
                const items = groups[groupKey];
                if (!Array.isArray(items) || !items.length) {
                    return;
                }
                totalVisible += items.length;

                let buttonsHtml = '';
                items.forEach(function (item) {
                    const token = item.token;
                    const label = item.label;
                    const displayToken = compactTokenText(token);
                    buttonsHtml += '' +
                        '<button type="button" class="ucg-token-btn" draggable="true" data-token="' + escapeHtml(token) + '" title="' + escapeHtml(label) + '" aria-label="' + escapeHtml(label) + '">' +
                        '<span class="ucg-token-btn__text">' + escapeHtml(displayToken) + '</span>' +
                        '</button>';
                });

                const openAttr = ' open';
                html += '' +
                    '<details class="ucg-token-group"' + openAttr + '>' +
                    '  <summary>' + escapeHtml(tokenGroupTitle(groupKey)) + ' <span class="ucg-token-group__count">' + items.length + '</span></summary>' +
                    '  <div class="ucg-token-grid">' + buttonsHtml + '</div>' +
                    '</details>';
            });

            if (!totalVisible) {
                $tokens.html(jsT('<p class="ucg-muted">Переменные не найдены.</p>'));
                return;
            }

            $tokens.html(html);
        }

        function availableOperators() {
            const operators = Array.isArray(state.schema.filter_operators) ? state.schema.filter_operators : [];
            if (!operators.length) {
                return [
                    { value: 'is_empty', label: jsT('пусто') },
                    { value: 'not_empty', label: jsT('не пусто') },
                    { value: 'contains', label: jsT('содержит') },
                    { value: 'equals', label: jsT('равно') }
                ];
            }
            return operators;
        }

        function isTaxonomyFilterField(fieldValue) {
            return String(fieldValue || '').indexOf('tax:') === 0;
        }

        function availableOperatorsForField(fieldValue) {
            const operators = availableOperators();
            if (!isTaxonomyFilterField(fieldValue)) {
                return operators;
            }

            const allowed = {
                is_empty: true,
                not_empty: true,
                contains: true,
                not_contains: true,
                equals: true,
                not_equals: true
            };

            return operators.filter(function (op) {
                const value = op && op.value ? String(op.value) : '';
                return !!allowed[value];
            });
        }

        function renderFilterOperatorOptions($row, preferredOperator) {
            if (!$row || !$row.length) {
                return;
            }

            const fieldValue = String($row.find('.ucg-filter-field').val() || '');
            const operators = availableOperatorsForField(fieldValue);
            const sourceOperators = operators.length ? operators : availableOperators();
            const $operator = $row.find('.ucg-filter-operator');
            let selectedOperator = String(typeof preferredOperator !== 'undefined' ? preferredOperator : ($operator.val() || ''));
            let selectedExists = false;
            let html = '';

            sourceOperators.forEach(function (op) {
                const value = op && op.value ? String(op.value) : '';
                if (!value) {
                    return;
                }
                const label = op && op.label ? String(op.label) : value;
                if (value === selectedOperator) {
                    selectedExists = true;
                }
                html += '<option value="' + escapeHtml(value) + '">' + escapeHtml(label) + '</option>';
            });

            if (!selectedExists) {
                const first = sourceOperators[0] && sourceOperators[0].value ? String(sourceOperators[0].value) : '';
                selectedOperator = first;
            }

            destroyEnhancedSelectInstance($operator);
            $operator.html(html);
            if (selectedOperator) {
                $operator.val(selectedOperator);
            }
            initEnhancedSelects($operator);
            updateFilterRowInputVisibility($row);
        }

        function renderFilterRow(filter) {
            const fields = Array.isArray(state.schema.filter_fields) ? state.schema.filter_fields : [];
            let selectedFieldValue = filter && filter.field ? String(filter.field) : '';
            if (!selectedFieldValue && fields.length) {
                selectedFieldValue = fields[0] && fields[0].value ? String(fields[0].value) : '';
            }

            let fieldHtml = '';
            fields.forEach(function (field) {
                const value = field && field.value ? String(field.value) : '';
                if (!value) {
                    return;
                }
                const label = field && field.label ? String(field.label) : value;
                const selected = selectedFieldValue === value ? ' selected' : '';
                fieldHtml += '<option value="' + escapeHtml(value) + '"' + selected + '>' + escapeHtml(label) + '</option>';
            });

            const operators = availableOperatorsForField(selectedFieldValue);
            let selectedOperator = filter && filter.operator ? String(filter.operator) : '';
            let selectedOperatorExists = false;
            let opHtml = '';
            operators.forEach(function (op) {
                const value = op && op.value ? String(op.value) : '';
                if (!value) {
                    return;
                }
                const label = op && op.label ? String(op.label) : value;
                if (selectedOperator === value) {
                    selectedOperatorExists = true;
                }
                const selected = selectedOperator === value ? ' selected' : '';
                opHtml += '<option value="' + escapeHtml(value) + '"' + selected + '>' + escapeHtml(label) + '</option>';
            });
            if (!selectedOperatorExists && operators.length) {
                selectedOperator = operators[0] && operators[0].value ? String(operators[0].value) : '';
            }

            const value = filter && typeof filter.value !== 'undefined' ? String(filter.value) : '';
            const rowHtml = '' +
                '<div class="ucg-filter-row-item">' +
                jsT('  <select class="ucg-filter-field ucg-enhanced-select" data-search-enabled="false" data-placeholder="Поле">') + fieldHtml + '</select>' +
                '  <select class="ucg-filter-operator ucg-enhanced-select" data-search-enabled="false">' + opHtml + '</select>' +
                '  <input type="text" class="ucg-filter-value" value="' + escapeHtml(value) + jsT('" placeholder="значение">') +
                jsT('  <button type="button" class="button button-small ucg-remove-filter-row">Удалить</button>') +
                '</div>';

            $filterRows.append(rowHtml);
            const $newRow = $filterRows.find('.ucg-filter-row-item').last();
            if (selectedOperator) {
                $newRow.find('.ucg-filter-operator').val(selectedOperator);
            }
            updateFilterRowInputVisibility($newRow);
            initEnhancedSelects($newRow);
        }

        function updateFilterRowInputVisibility($row) {
            const operator = String($row.find('.ucg-filter-operator').val() || '');
            const field = String($row.find('.ucg-filter-field').val() || '');
            const $value = $row.find('.ucg-filter-value');
            const hideValue = operator === 'is_empty' || operator === 'not_empty';
            $value.prop('disabled', hideValue).toggle(!hideValue);
            $value.attr('placeholder', isTaxonomyFilterField(field) ? jsT('термин (название или slug)') : jsT('значение'));
        }

        function clearFilters() {
            $filterRows.empty();
        }

        function updateTemplateMode() {
            const templateId = Number($templateSelect.val() || 0);
            const isSelected = templateId > 0;
            const wantSave = $saveTemplateChanges.is(':checked');

            if (isSelected) {
                $templateNameWrap.hide();
                $saveTemplateLabel.text(jsT('Сохранить изменения в шаблоне'));
                return;
            }

            if (wantSave) {
                $templateNameWrap.show();
            } else {
                $templateNameWrap.hide();
            }
            $saveTemplateLabel.text(jsT('Сохранить шаблон'));
        }

        function applyStep2ModeVisibility() {
            const scenario = getScenario();
            const supportsCreateMode = scenarioSupportsCreateNewMode(scenario);
            if (!supportsCreateMode && $runTargetMode.length) {
                $runTargetMode.filter('[value="update_existing"]').prop('checked', true);
            }

            const createMode = supportsCreateMode && getRunTargetMode() === 'create_new';
            if ($runTargetModeWrap.length) {
                $runTargetModeWrap.toggle(supportsCreateMode);
            }
            if ($step2CreateWrap.length) {
                $step2CreateWrap.prop('hidden', !createMode).toggle(createMode);
            }
            if ($step2UpdateWrap.length) {
                $step2UpdateWrap.prop('hidden', createMode).toggle(!createMode);
            }
            if ($createModeTokenWarning.length) {
                $createModeTokenWarning.prop('hidden', !createMode).toggle(createMode);
            }
        }

        function getPlannedCount() {
            if (isCreateNewMode()) {
                return collectCreateTopics().length;
            }
            if (getSelectionMode() === 'filtered') {
                return Number(state.total || 0);
            }
            return Number(state.selectedIds.size || 0);
        }

        function getSelectionMode() {
            if (isCreateNewMode()) {
                return 'create_new';
            }
            return String($selectionMode.filter(':checked').val() || 'selected');
        }

        function resetQuoteEstimateState() {
            cancelQuoteRefreshRequest();
            state.quoteEstimate = null;
            state.quoteEstimateSignature = '';
            state.quoteError = '';
            state.quoteFailedSignature = '';
            state.quoteFailedAt = 0;
        }

        function cancelQuoteRefreshRequest() {
            if (state.quoteRequestTimer) {
                window.clearTimeout(state.quoteRequestTimer);
            }
            state.quoteRequestTimer = null;
            if (state.quoteRequestXhr && typeof state.quoteRequestXhr.abort === 'function') {
                state.quoteRequestXhr.abort();
            }
            state.quoteRequestXhr = null;
            state.quoteRequestInFlight = false;
            state.quoteRequestSignature = '';
        }

        function buildRunQuoteRequestContext() {
            const scenario = getScenario();
            const postType = scenarioRequiresProductPostType(scenario) ? 'product' : String($postType.val() || '');
            const plannedCount = Math.max(0, Number(getPlannedCount() || 0));
            const selectionMode = getSelectionMode();
            const createTopics = selectionMode === 'create_new' ? collectCreateTopics() : [];
            const model = getEnhancedSelectValue($modelSelect) || String(state.defaultModel || 'auto');
            const lengthOptionId = Number($lengthOption.val() || 0);
            const varyLength = $varyLength.is(':checked') ? 1 : 0;
            const itemsPerPost = scenarioSupportsItemsPerPost(scenario) ? normalizeItemsPerPost($itemsPerPost.val()) : 1;
            const templateBody = String($templateBody.val() || '').trim();
            const templateBodySeoTitle = String($templateBodySeoTitle.val() || '').trim();
            const templateBodySeoDescription = String($templateBodySeoDescription.val() || '').trim();

            let aiFields = [];
            if (scenarioSupportsMultiFields(scenario)) {
                const hasRenderedAiRows = getAiRowsContainers().length && getAiRowsContainers().find('.ucg-ai-field-row').length > 0;
                aiFields = collectAiFieldsForRun();
                if (!hasRenderedAiRows && !aiFields.length) {
                    aiFields = normalizeTemplateAiFields(state.templateAiFields);
                }
                aiFields = aiFields.filter(function (field) {
                    return !!(field && field.enabled && String(field.prompt || '').trim() !== '');
                });
            } else if (scenario === 'seo_tags') {
                aiFields = buildSeoAiFieldsForRun(templateBodySeoTitle, templateBodySeoDescription, lengthOptionId).filter(function (field) {
                    return !!(field && String(field.prompt || '').trim() !== '');
                });
            }

            const signaturePayload = {
                scenario: scenario,
                post_type: postType,
                selection_mode: selectionMode,
                planned_count: plannedCount,
                model: model,
                create_topics: createTopics,
                length_option_id: lengthOptionId,
                vary_length: varyLength,
                items_per_post: itemsPerPost,
                template_body: templateBody,
                template_body_seo_title: templateBodySeoTitle,
                template_body_seo_description: templateBodySeoDescription,
                ai_fields: aiFields
            };
            const signature = JSON.stringify(signaturePayload);

            return {
                signature: signature,
                plannedCount: plannedCount,
                payload: {
                    action: 'ucg_wizard_quote',
                    nonce: ucgAdmin.nonce,
                    signature: signature,
                    scenario: scenario,
                    post_type: postType,
                    selection_mode: selectionMode,
                    planned_count: plannedCount,
                    model: model,
                    create_topics: createTopics.join('\n'),
                    length_option_id: lengthOptionId,
                    vary_length: varyLength,
                    items_per_post: itemsPerPost,
                    template_body: templateBody,
                    template_body_seo_title: templateBodySeoTitle,
                    template_body_seo_description: templateBodySeoDescription,
                    ai_fields: JSON.stringify(aiFields)
                }
            };
        }

        function requestRunQuoteEstimate(context, requestSeq) {
            if (!context || !context.signature) {
                return;
            }

            const signature = String(context.signature);
            if (state.quoteRequestXhr && typeof state.quoteRequestXhr.abort === 'function') {
                state.quoteRequestXhr.abort();
            }

            state.quoteRequestInFlight = true;
            state.quoteRequestSignature = signature;
            state.quoteError = '';

            state.quoteRequestXhr = $.post(ucgAdmin.ajaxUrl, context.payload).done(function (response) {
                if (requestSeq !== state.quoteRequestSeq) {
                    return;
                }
                if (!response || !response.success) {
                    const msg = response && response.data && response.data.message
                        ? String(response.data.message)
                        : jsT('Не удалось уточнить стоимость через API.');
                    state.quoteError = msg;
                    if (!state.quoteEstimate || state.quoteEstimate.signature !== signature) {
                        state.quoteEstimate = null;
                        state.quoteEstimateSignature = '';
                    }
                    state.quoteFailedSignature = signature;
                    state.quoteFailedAt = Date.now();
                    renderRunSummary();
                    return;
                }

                const data = response.data || {};
                const responseSignature = data && data.signature ? String(data.signature) : signature;
                state.quoteEstimate = {
                    signature: responseSignature,
                    data: data
                };
                state.quoteEstimateSignature = responseSignature;
                state.quoteError = '';
                state.quoteFailedSignature = '';
                state.quoteFailedAt = 0;
                renderRunSummary();
            }).fail(function (_xhr, textStatus) {
                if (requestSeq !== state.quoteRequestSeq || textStatus === 'abort') {
                    return;
                }
                state.quoteError = jsT('Не удалось уточнить стоимость через API.');
                if (!state.quoteEstimate || state.quoteEstimate.signature !== signature) {
                    state.quoteEstimate = null;
                    state.quoteEstimateSignature = '';
                }
                state.quoteFailedSignature = signature;
                state.quoteFailedAt = Date.now();
                renderRunSummary();
            }).always(function () {
                if (requestSeq !== state.quoteRequestSeq) {
                    return;
                }
                state.quoteRequestInFlight = false;
                state.quoteRequestXhr = null;
            });
        }

        function scheduleRunQuoteRefresh() {
            if (state.step !== 3) {
                cancelQuoteRefreshRequest();
                return;
            }

            const context = buildRunQuoteRequestContext();
            if (!context || !context.signature) {
                cancelQuoteRefreshRequest();
                return;
            }

            const signature = String(context.signature);
            if (context.plannedCount <= 0) {
                cancelQuoteRefreshRequest();
                return;
            }
            if (state.quoteEstimate && state.quoteEstimate.signature === signature) {
                if (state.quoteRequestTimer) {
                    window.clearTimeout(state.quoteRequestTimer);
                    state.quoteRequestTimer = null;
                }
                return;
            }
            if (state.quoteRequestInFlight && state.quoteRequestSignature === signature) {
                return;
            }

            const failCooldownMs = 10000;
            if (state.quoteFailedSignature === signature && (Date.now() - Number(state.quoteFailedAt || 0)) < failCooldownMs) {
                return;
            }

            if (state.quoteRequestTimer) {
                window.clearTimeout(state.quoteRequestTimer);
            }
            const requestSeq = state.quoteRequestSeq + 1;
            state.quoteRequestSeq = requestSeq;
            state.quoteRequestTimer = window.setTimeout(function () {
                state.quoteRequestTimer = null;
                requestRunQuoteEstimate(context, requestSeq);
            }, 450);
        }

        function updatePreviewTable(items) {
            state.currentItems = Array.isArray(items) ? items : [];
            if (!state.currentItems.length) {
                $previewBody.html(jsT('<tr><td colspan="5">Ничего не найдено по фильтрам.</td></tr>'));
                $('#ucg-preview-select-page').prop('checked', false);
                return;
            }

            let html = '';
            state.currentItems.forEach(function (item) {
                const id = Number(item && item.id ? item.id : 0);
                if (!id) {
                    return;
                }
                const checked = state.selectedIds.has(id) ? ' checked' : '';
                const title = item && item.title ? String(item.title) : '';
                const status = item && item.status_label ? String(item.status_label) : '';
                const date = item && item.date ? String(item.date) : '';

                html += '<tr>' +
                    '<td><input type="checkbox" class="ucg-preview-checkbox" value="' + id + '"' + checked + '></td>' +
                    '<td>#' + id + '</td>' +
                    '<td>' + escapeHtml(title) + '</td>' +
                    '<td>' + escapeHtml(status) + '</td>' +
                    '<td>' + escapeHtml(date) + '</td>' +
                    '</tr>';
            });

            $previewBody.html(html);
            syncPageCheckbox();
        }

        function syncPageCheckbox() {
            if (!state.currentItems.length) {
                $('#ucg-preview-select-page').prop('checked', false);
                return;
            }

            const allSelected = state.currentItems.every(function (item) {
                const id = Number(item && item.id ? item.id : 0);
                return id > 0 && state.selectedIds.has(id);
            });
            $('#ucg-preview-select-page').prop('checked', allSelected);
        }

        function buildPaginationItems() {
            const total = Number(state.totalPages || 1);
            const current = Number(state.page || 1);
            const items = [];

            if (total <= 7) {
                for (let i = 1; i <= total; i += 1) {
                    items.push(i);
                }
                return items;
            }

            items.push(1);
            if (current > 3) {
                items.push('dots-left');
            }

            const start = Math.max(2, current - 1);
            const end = Math.min(total - 1, current + 1);
            for (let i = start; i <= end; i += 1) {
                items.push(i);
            }

            if (current < total - 2) {
                items.push('dots-right');
            }
            items.push(total);

            return items;
        }

        function updatePagination() {
            if (!$previewPagination.length) {
                return;
            }

            if (state.totalPages <= 1) {
                $previewPagination.html('');
                return;
            }

            const items = buildPaginationItems();
            let html = '';

            items.forEach(function (item) {
                if (typeof item === 'number') {
                    const isActive = item === state.page ? ' is-active' : '';
                    html += '<button type="button" class="button button-small ucg-page-btn' + isActive + '" data-page="' + item + '">' + item + '</button>';
                    return;
                }
                html += jsT('<span class="ucg-page-dots">…</span>');
            });

            $previewPagination.html(html);
        }

        function updateSummary() {
            const mode = getSelectionMode();
            const createMode = mode === 'create_new';
            const totalFound = Number(state.total || 0);
            const selectedManual = Number(state.selectedIds.size || 0);
            const createCount = createMode ? collectCreateTopics().length : 0;
            const selectedForRun = createMode ? createCount : (mode === 'filtered' ? totalFound : selectedManual);

            if ($previewFoundCount.length) {
                $previewFoundCount.text(String(totalFound));
            }
            if ($selectionModeFilteredTotal.length) {
                $selectionModeFilteredTotal.text(String(totalFound));
            }
            if ($previewSelectedCount.length) {
                $previewSelectedCount.text(String(selectedForRun));
                $previewSelectedCount.closest('.ucg-step2-stat').toggleClass('is-active', selectedForRun > 0);
            }

            if (createMode) {
                $previewSummary.text(jsT('Будет создано элементов: ') + createCount + '.');
            } else if (mode === 'filtered') {
                $previewSummary.text(jsT('Будут использованы все найденные записи: ') + totalFound + '.');
            } else {
                $previewSummary.text(jsT('Выбрано вручную: ') + selectedManual + jsT('. Найдено по фильтру: ') + totalFound + '.');
            }
            renderRunSummary();
        }

        function previewPosts(page, $button) {
            const scenario = getScenario();
            const postType = scenarioRequiresProductPostType(scenario) ? 'product' : String($postType.val() || '');
            if (!postType) {
                setRunStatus(jsT('Выберите тип записей.'), true);
                return;
            }
            if (isCreateNewMode()) {
                state.page = 1;
                state.total = 0;
                state.totalPages = 1;
                state.currentItems = [];
                updatePreviewTable([]);
                updatePagination();
                updateSummary();
                setRunStatus('', false);
                return;
            }

            const filters = normalizeFilters();
            const targetPage = Math.max(1, Number(page || 1));
            setRunStatus(jsT('Загружаем записи...'), false);
            setButtonLoading($button, true);

            $.post(ucgAdmin.ajaxUrl, {
                action: 'ucg_wizard_preview',
                nonce: ucgAdmin.nonce,
                scenario: scenario,
                post_type: postType,
                filters: JSON.stringify(filters),
                page: targetPage,
                per_page: state.perPage
            }).done(function (response) {
                if (!response.success) {
                    const msg = response.data && response.data.message ? response.data.message : jsT('Не удалось загрузить записи.');
                    setRunStatus(msg, true);
                    return;
                }

                const data = response.data || {};
                state.page = Number(data.page || 1);
                state.totalPages = Number(data.total_pages || 1);
                state.total = Number(data.total || 0);
                updatePreviewTable(Array.isArray(data.items) ? data.items : []);
                updatePagination();
                updateSummary();
                updateSelectedCount();
                setRunStatus(jsT('Найдено записей: ') + state.total + '.', false);
            }).fail(function () {
                setRunStatus(jsT('AJAX ошибка при фильтрации записей.'), true);
            }).always(function () {
                setButtonLoading($button, false);
            });
        }

        function loadTemplate(templateId, onDone) {
            const id = Number(templateId || 0);
            updateTemplateMode();
            if (!id) {
                $templateBody.val('');
                $templateBodySeoTitle.val('');
                $templateBodySeoDescription.val('');
                state.templateAiFields = [];
                state.templateStaticFields = [];
                updateScenarioTemplateInputs();
                renderRunSummary();
                if (typeof onDone === 'function') {
                    onDone();
                }
                return;
            }

            $.post(ucgAdmin.ajaxUrl, {
                action: 'ucg_wizard_load_template',
                nonce: ucgAdmin.nonce,
                scenario: getScenario(),
                template_id: id
            }).done(function (response) {
                if (!response.success) {
                    const msg = response.data && response.data.message ? response.data.message : jsT('Не удалось загрузить шаблон.');
                    setRunStatus(msg, true);
                    return;
                }

                const data = response.data || {};
                const template = data.template || {};
                $templateBody.val(String(template.body || ''));
                $templateBodySeoTitle.val(String(template.seo_title_prompt || ''));
                $templateBodySeoDescription.val(String(template.seo_description_prompt || ''));
                state.templateAiFields = normalizeTemplateAiFields(template.fields);
                state.templateStaticFields = normalizeTemplateStaticFields(template.static_fields, getScenario());
                if (getScenario() === 'seo_tags') {
                    const fallback = String(template.body || '');
                    if (!$templateBodySeoTitle.val() && fallback) {
                        $templateBodySeoTitle.val(fallback);
                    }
                    if (!$templateBodySeoDescription.val() && fallback) {
                        $templateBodySeoDescription.val(fallback);
                    }
                }
                renderTokenButtons($tokens, Array.isArray(data.tokens) ? data.tokens : []);
                updateScenarioTemplateInputs();
                renderRunSummary();
                if (typeof onDone === 'function') {
                    onDone();
                }
            }).fail(function () {
                setRunStatus(jsT('AJAX ошибка при загрузке шаблона.'), true);
                if (typeof onDone === 'function') {
                    onDone();
                }
            });
        }

        function refreshSchema(postType, done, $button, forceRefreshLengths) {
            const scenario = getScenario();
            setButtonLoading($button, true);
            $.post(ucgAdmin.ajaxUrl, {
                action: 'ucg_wizard_schema',
                nonce: ucgAdmin.nonce,
                scenario: scenario,
                post_type: postType,
                force_refresh_lengths: forceRefreshLengths ? 1 : 0
            }).done(function (response) {
                if (!response.success) {
                    const msg = response.data && response.data.message ? response.data.message : jsT('Не удалось загрузить схему.');
                    setRunStatus(msg, true);
                    return;
                }

                resetQuoteEstimateState();
                state.schema = response.data || {};
                if (state.schema && state.schema.scenario) {
                    state.scenario = String(state.schema.scenario);
                }
                if (state.schema && state.schema.default_model) {
                    state.defaultModel = String(state.schema.default_model);
                }
                if ($scenarioInputs.length && state.scenario) {
                    $scenarioInputs.prop('checked', false);
                    $scenarioInputs.filter('[value="' + state.scenario + '"]').prop('checked', true);
                    setScenarioCardState();
                }
                renderTargetFields();
                renderTextLengthOptions();
                renderGenerationModels();
                state.templateAiFields = [];
                state.templateStaticFields = [];
                renderTemplates();
                renderWizardTokens();
                applyStyleDefaultsFromSchema();
                clearFilters();
                state.selectedIds.clear();
                updateSelectedCount();
                applyStep2ModeVisibility();
                updateSummary();
                if (typeof done === 'function') {
                    done();
                }
            }).fail(function () {
                setRunStatus(jsT('AJAX ошибка при загрузке схемы.'), true);
            }).always(function () {
                setButtonLoading($button, false);
            });
        }

        function applyWizardPrefill() {
            if (state.prefillApplied) {
                return false;
            }
            const prefill = state.prefill && typeof state.prefill === 'object' ? state.prefill : {};
            if (!Object.keys(prefill).length) {
                state.prefillApplied = true;
                return false;
            }
            state.prefillApplied = true;

            const desiredScenario = String(prefill.scenario || getScenario() || 'field_update');
            let desiredPostType = String(prefill.post_type || $postType.val() || '');
            if (scenarioRequiresProductPostType(desiredScenario) && $postType.find('option[value="product"]').length) {
                desiredPostType = 'product';
            }
            const currentScenario = getScenario();
            const currentPostType = String($postType.val() || '');
            const needsScenarioRefresh = !!(desiredScenario && desiredScenario !== currentScenario);
            const needsPostTypeRefresh = !!(desiredPostType && desiredPostType !== currentPostType);

            const applyValues = function () {
                if (prefill.target_field) {
                    setEnhancedSelectValue($targetField, String(prefill.target_field));
                }

                const prefillLength = Number(prefill.length_option_id || 0);
                if (prefillLength > 0 && $lengthOption.find('option[value="' + prefillLength + '"]').length) {
                    setEnhancedSelectValue($lengthOption, String(prefillLength));
                }

                if (prefill.model && $modelSelect.find('option[value="' + String(prefill.model) + '"]').length) {
                    setEnhancedSelectValue($modelSelect, String(prefill.model));
                }

                if ($varyLength.length) {
                    $varyLength.prop('checked', Number(prefill.vary_length || 0) === 1);
                }
                if ($itemsPerPost.length && Number(prefill.items_per_post || 0) > 0) {
                    $itemsPerPost.val(String(normalizeItemsPerPost(prefill.items_per_post)));
                }
                if ($wooRatingMin.length && Number(prefill.rating_min || 0) > 0) {
                    $wooRatingMin.val(String(normalizeWooRatingValue(prefill.rating_min)));
                }
                if ($wooRatingMax.length && Number(prefill.rating_max || 0) > 0) {
                    $wooRatingMax.val(String(normalizeWooRatingValue(prefill.rating_max)));
                }
                if ($publishDateFrom.length && prefill.publish_date_from) {
                    $publishDateFrom.val(String(prefill.publish_date_from));
                }
                if ($publishDateTo.length && prefill.publish_date_to) {
                    $publishDateTo.val(String(prefill.publish_date_to));
                }
                if (prefill.style_language) {
                    setEnhancedSelectValue($styleLanguage, String(prefill.style_language));
                }
                if (prefill.style_tone) {
                    setEnhancedSelectValue($styleTone, String(prefill.style_tone));
                }

                if (prefill.template_body) {
                    $templateBody.val(String(prefill.template_body));
                }
                if (prefill.template_body_seo_title) {
                    $templateBodySeoTitle.val(String(prefill.template_body_seo_title));
                }
                if (prefill.template_body_seo_description) {
                    $templateBodySeoDescription.val(String(prefill.template_body_seo_description));
                }

                const prefillAiFields = normalizeTemplateAiFields(prefill.ai_fields);
                const prefillStaticFields = normalizeTemplateStaticFields(prefill.static_fields, desiredScenario);
                state.templateAiFields = prefillAiFields;
                state.templateStaticFields = prefillStaticFields;

                clearFilters();
                const filters = Array.isArray(prefill.filters) ? prefill.filters : [];
                filters.forEach(function (filter) {
                    renderFilterRow(filter);
                });

                const mode = String(prefill.selection_mode || 'selected');
                const supportsCreateMode = scenarioSupportsCreateNewMode(getScenario());
                if (supportsCreateMode && $runTargetMode.length) {
                    const runTargetMode = mode === 'create_new' ? 'create_new' : 'update_existing';
                    $runTargetMode.prop('checked', false);
                    $runTargetMode.filter('[value="' + runTargetMode + '"]').prop('checked', true);
                } else if ($runTargetMode.length) {
                    $runTargetMode.prop('checked', false);
                    $runTargetMode.filter('[value="update_existing"]').prop('checked', true);
                }
                const selectionMode = mode === 'filtered' ? 'filtered' : 'selected';
                $selectionMode.prop('checked', false);
                $selectionMode.filter('[value="' + selectionMode + '"]').prop('checked', true);
                const prefillTopics = normalizeCreateTopics(prefill.create_topics || prefill.create_topics_text || '');
                if ($createTopics.length) {
                    $createTopics.val(prefillTopics.join('\n'));
                }
                applyStep2ModeVisibility();
                updateSummary();

                const templateId = Number(prefill.template_id || 0);
                if (templateId > 0 && $templateSelect.find('option[value="' + templateId + '"]').length) {
                    setEnhancedSelectValue($templateSelect, String(templateId));
                    loadTemplate(templateId, function () {
                        if (prefill.template_body) {
                            $templateBody.val(String(prefill.template_body));
                        }
                        if (prefill.template_body_seo_title) {
                            $templateBodySeoTitle.val(String(prefill.template_body_seo_title));
                        }
                        if (prefill.template_body_seo_description) {
                            $templateBodySeoDescription.val(String(prefill.template_body_seo_description));
                        }
                        if (prefillAiFields.length) {
                            state.templateAiFields = prefillAiFields;
                        }
                        if (prefillStaticFields.length) {
                            state.templateStaticFields = prefillStaticFields;
                        }
                        updateScenarioTemplateInputs();
                        renderRunSummary();
                    });
                } else {
                    setEnhancedSelectValue($templateSelect, '');
                    updateTemplateMode();
                    updateScenarioTemplateInputs();
                    renderRunSummary();
                }

                state.page = 1;
                if (!isCreateNewMode()) {
                    previewPosts(1, $('#ucg-preview-posts'));
                } else {
                    updateSummary();
                }

                const repeatRunId = Number(prefill.repeat_run_id || 0);
                if (repeatRunId > 0) {
                    setRunStatus(jsT('Загружены параметры запуска #') + repeatRunId + '.', false, {
                        type: 'info',
                        key: 'run-prefill',
                        force: true
                    });
                }
            };

            if (needsScenarioRefresh && desiredScenario) {
                $scenarioInputs.prop('checked', false);
                $scenarioInputs.filter('[value="' + desiredScenario + '"]').prop('checked', true);
                state.scenario = desiredScenario;
                setScenarioCardState();
            }
            if (needsPostTypeRefresh && desiredPostType) {
                setEnhancedSelectValue($postType, desiredPostType);
            }

            if (needsScenarioRefresh || needsPostTypeRefresh) {
                const postTypeForSchema = desiredPostType || String($postType.val() || '');
                refreshSchema(postTypeForSchema, applyValues, $('#ucg-step-1-next'), false);
                return true;
            }

            applyValues();
            return true;
        }

        function startRun($button) {
            const scenario = getScenario();
            const postType = scenarioRequiresProductPostType(scenario) ? 'product' : String($postType.val() || '');
            const targetField = String($targetField.val() || '');
            const itemsPerPost = scenarioSupportsItemsPerPost(scenario) ? normalizeItemsPerPost($itemsPerPost.val()) : 1;
            const wooRatingRange = collectWooRatingRangeForRun(scenario);
            const templateId = Number($templateSelect.val() || 0);
            const templateName = String($templateName.val() || '').trim();
            const templateBody = String($templateBody.val() || '').trim();
            const templateBodySeoTitle = String($templateBodySeoTitle.val() || '').trim();
            const templateBodySeoDescription = String($templateBodySeoDescription.val() || '').trim();
            const mode = getSelectionMode();
            const createMode = mode === 'create_new';
            const createTopics = createMode ? collectCreateTopics() : [];
            const createCount = createMode ? createTopics.length : 0;
            const filters = createMode ? [] : normalizeFilters();
            const lengthOptionId = Number($lengthOption.val() || 0);
            const model = getEnhancedSelectValue($modelSelect) || String(state.defaultModel || 'auto');
            const varyLength = $varyLength.is(':checked') ? 1 : 0;
            const publishDateRange = collectPublishDateRangeForRun(scenario);
            const styleLanguage = String($styleLanguage.val() || (state.schema && state.schema.settings && state.schema.settings.default_language) || 'auto');
            const styleTone = String($styleTone.val() || (state.schema && state.schema.settings && state.schema.settings.default_tone) || 'neutral');
            const aiFields = scenarioSupportsMultiFields(scenario) ? collectAiFieldsForRun() : [];
            const staticFields = scenarioSupportsMultiFields(scenario) ? collectStaticFieldsForRun() : [];

            if (!postType) {
                setRunStatus(jsT('Выберите тип записей.'), true);
                switchStep(2);
                return;
            }

            if (!targetField && !scenarioSupportsItemsPerPost(scenario) && !scenarioSupportsMultiFields(scenario)) {
                setRunStatus(jsT('Выберите целевое поле.'), true);
                switchStep(2);
                return;
            }

            if (!publishDateRange.valid) {
                setRunStatus(publishDateRange.message || jsT('Проверьте диапазон дат публикации.'), true);
                return;
            }

            if (!wooRatingRange.valid) {
                setRunStatus(wooRatingRange.message || jsT('Проверьте диапазон рейтинга.'), true);
                return;
            }

            if (scenario === 'seo_tags') {
                if (!templateBodySeoTitle || !templateBodySeoDescription) {
                    setRunStatus(jsT('Заполните шаблоны для SEO title и SEO description.'), true);
                    return;
                }
            } else if (scenarioSupportsMultiFields(scenario)) {
                const hasEnabledAi = aiFields.some(function (field) {
                    return !!(field && field.enabled && String(field.prompt || '').trim() !== '');
                });
                const hasEnabledAiWithoutPrompt = aiFields.some(function (field) {
                    return !!(field && field.enabled && String(field.prompt || '').trim() === '');
                });
                const hasEnabledStatic = hasEnabledStaticFields(staticFields);
                if (hasEnabledAiWithoutPrompt) {
                    setRunStatus(jsT('Для включённых AI-полей заполните промпт.'), true);
                    return;
                }
                if (!hasEnabledAi && !hasEnabledStatic) {
                    setRunStatus(jsT('Выберите хотя бы одно AI или static поле.'), true);
                    return;
                }
            } else if (!templateBody) {
                setRunStatus(jsT('Шаблон пустой. Заполните текст.'), true);
                return;
            }

            if (lengthOptionId <= 0 && !scenarioSupportsMultiFields(scenario)) {
                setRunStatus(jsT('Выберите диапазон длины текста.'), true);
                return;
            }

            if (templateId <= 0 && $saveTemplateChanges.is(':checked') && !templateName) {
                setRunStatus(jsT('Введите название шаблона, чтобы сохранить его.'), true);
                return;
            }

            if (createMode && createCount <= 0) {
                setRunStatus(jsT('Добавьте хотя бы одну тему для создания.'), true);
                switchStep(2);
                return;
            }

            if (!createMode && mode === 'selected' && state.selectedIds.size === 0) {
                setRunStatus(jsT('Выберите записи вручную или переключитесь на режим "все найденные".'), true);
                switchStep(2);
                return;
            }

            const startingText = jsT('Генерация запущена...');
            setRunStatus(startingText, false, {
                type: 'loading',
                key: 'run-create',
                force: true
            });
            setButtonLoading($button, true);

            const payload = {
                action: 'ucg_wizard_create_run',
                nonce: ucgAdmin.nonce,
                scenario: scenario,
                post_type: postType,
                target_field: targetField,
                items_per_post: itemsPerPost,
                rating_min: wooRatingRange.ratingMin,
                rating_max: wooRatingRange.ratingMax,
                style_language: styleLanguage,
                style_tone: styleTone,
                model: model,
                template_id: templateId,
                template_name: templateName,
                template_body: templateBody,
                template_body_seo_title: templateBodySeoTitle,
                template_body_seo_description: templateBodySeoDescription,
                length_option_id: lengthOptionId,
                vary_length: varyLength,
                publish_date_from: publishDateRange.from,
                publish_date_to: publishDateRange.to,
                save_template: $saveTemplateChanges.is(':checked') ? 1 : 0,
                selection_mode: mode,
                create_count: createCount,
                create_topics: createTopics.join('\n'),
                selected_ids: JSON.stringify(createMode ? [] : Array.from(state.selectedIds)),
                filters: JSON.stringify(filters)
            };
            if (scenarioSupportsMultiFields(scenario)) {
                payload.ai_fields = JSON.stringify(aiFields);
                payload.static_fields = JSON.stringify(staticFields);
            } else if (scenario === 'seo_tags') {
                payload.ai_fields = JSON.stringify(
                    buildSeoAiFieldsForRun(templateBodySeoTitle, templateBodySeoDescription, lengthOptionId)
                );
            }

            $.post(ucgAdmin.ajaxUrl, payload).done(function (response) {
                if (!response.success) {
                    const msg = response.data && response.data.message ? response.data.message : jsT('Не удалось создать запуск.');
                    setRunStatus(msg, true, { type: 'error', key: 'run-create', force: true });
                    return;
                }

                const data = response.data || {};
                const runId = Number(data.run_id || 0);
                const queued = Number(data.queued || 0);
                const queueWord = pluralRu(queued, [jsT('запись'), jsT('записи'), jsT('записей')]);
                const queueDetail = jsT('В очереди: ') + queued + ' ' + queueWord + '.';
                const progressUrl = String(data.progress_url || (runId > 0 ? ('admin.php?page=ucg-run-progress&run_id=' + runId) : ''));

                if (progressUrl) {
                    setRunStatus(jsT('Генерация запущена.'), false, {
                        type: 'success',
                        key: 'run-create',
                        detail: queueDetail,
                        force: true
                    });
                    window.setTimeout(function () {
                        window.location.href = progressUrl;
                    }, 250);
                    return;
                }

                setRunStatus(jsT('Генерация запущена.'), false, {
                    type: 'success',
                    key: 'run-create',
                    detail: queueDetail,
                    force: true
                });
            }).fail(function () {
                setRunStatus(jsT('AJAX ошибка при создании запуска.'), true, { type: 'error', key: 'run-create', force: true });
            }).always(function () {
                setButtonLoading($button, false);
            });
        }

        function parseLegacyMultiFieldPreview(rawPreview) {
            const text = String(rawPreview || '').trim();
            if (!text || text.indexOf(':\n') === -1) {
                return [];
            }

            const chunks = text.split(/\n{2,}/);
            const parsed = [];
            let invalidChunkFound = false;
            chunks.forEach(function (chunk) {
                const normalizedChunk = String(chunk || '').trim();
                if (!normalizedChunk) {
                    return;
                }
                const match = normalizedChunk.match(/^([^:\n]{1,120}):\n([\s\S]*)$/);
                if (!match) {
                    invalidChunkFound = true;
                    return;
                }
                const label = String(match[1] || '').trim();
                const value = String(match[2] || '').trim();
                if (!label) {
                    invalidChunkFound = true;
                    return;
                }
                parsed.push({
                    label: label,
                    value: value
                });
            });

            if (invalidChunkFound || !parsed.length) {
                return [];
            }
            return parsed;
        }

        function renderExampleFieldCards(fields, title) {
            const normalizedFields = Array.isArray(fields) ? fields.filter(function (field) {
                return field && String(field.label || '').trim() !== '';
            }) : [];
            if (!normalizedFields.length) {
                return '';
            }

            const cardTitle = String(title || jsT('Пример генерации'));
            const gridClass = normalizedFields.length > 1 ? ' ucg-example-fields--grid' : '';
            const fieldsHtml = normalizedFields.map(function (field) {
                const label = String(field.label || '').trim();
                const value = String(field.value == null ? '' : field.value).trim();
                const images = Array.isArray(field.images) ? field.images.map(function (image) {
                    return String(image || '').trim();
                }).filter(function (image) {
                    return /^https?:\/\//i.test(image) || /^data:image\//i.test(image);
                }) : [];
                const imagesHtml = images.length ? (
                    '<div class="ucg-example-field__images">' +
                    images.map(function (imageSrc, imageIndex) {
                        return (
                            '<figure class="ucg-example-field__image-item">' +
                            '  <img class="ucg-example-field__image" src="' + escapeHtml(imageSrc) + '" alt="' + escapeHtml(label + ' #' + (imageIndex + 1)) + '">' +
                            '</figure>'
                        );
                    }).join('') +
                    '</div>'
                ) : '';
                return '' +
                    '<article class="ucg-example-field">' +
                    '  <h5 class="ucg-example-field__label">' + escapeHtml(label) + '</h5>' +
                    '  <p class="ucg-example-field__value">' + escapeHtml(value || jsT('Пусто')) + '</p>' +
                    imagesHtml +
                    '</article>';
            }).join('');

            return '' +
                '<article class="ucg-example-card">' +
                '  <h4 class="ucg-example-card__title">' + escapeHtml(cardTitle) + '</h4>' +
                '  <div class="ucg-example-fields' + gridClass + '">' + fieldsHtml + '</div>' +
                '</article>';
        }

        function renderExampleOutput(preview) {
            if (!$exampleOutput.length) {
                return;
            }

            let html = '';
            if (preview && typeof preview === 'object') {
                const fieldsFromObject = Array.isArray(preview.fields) ? preview.fields.map(function (field) {
                    if (!field || typeof field !== 'object') {
                        return null;
                    }
                    const label = String(field.label || field.key || '').trim();
                    const value = String(field.value == null ? '' : field.value).trim();
                    const images = Array.isArray(field.images) ? field.images.map(function (image) {
                        return String(image || '').trim();
                    }).filter(function (image) {
                        return /^https?:\/\//i.test(image) || /^data:image\//i.test(image);
                    }) : [];
                    if (!label) {
                        return null;
                    }
                    return { label: label, value: value, images: images };
                }).filter(Boolean) : [];

                if (fieldsFromObject.length) {
                    const previewTitle = String(preview.title || jsT('Пример по полям'));
                    html = renderExampleFieldCards(fieldsFromObject, previewTitle);
                } else if (preview.title || preview.description) {
                    html = renderExampleFieldCards(
                        [
                            { label: 'SEO title', value: String(preview.title || '') },
                            { label: 'SEO description', value: String(preview.description || '') }
                        ],
                        jsT('SEO превью')
                    );
                } else if (preview.field_label || preview.text) {
                    html = renderExampleFieldCards(
                        [
                            { label: String(preview.field_label || jsT('Поле')), value: String(preview.text || '') }
                        ],
                        jsT('Пример поля')
                    );
                }
            }

            if (!html) {
                const previewText = String(preview == null ? '' : preview).trim();
                const parsedLegacyFields = parseLegacyMultiFieldPreview(previewText);
                if (parsedLegacyFields.length) {
                    html = renderExampleFieldCards(parsedLegacyFields, jsT('Пример по полям'));
                } else if (previewText) {
                    html = renderExampleFieldCards(
                        [{ label: jsT('Результат'), value: previewText }],
                        jsT('Пример результата')
                    );
                }
            }

            if (!html) {
                html = '<p class="ucg-example-output__empty">' + escapeHtml(jsT('Нет данных для превью.')) + '</p>';
            }
            $exampleOutput.html(html);
        }

        function generateExample($button) {
            const scenario = getScenario();
            const postType = scenarioRequiresProductPostType(scenario) ? 'product' : String($postType.val() || '');
            const targetField = String($targetField.val() || '');
            const wooRatingRange = collectWooRatingRangeForRun(scenario);
            const templateBody = String($templateBody.val() || '').trim();
            const templateBodySeoTitle = String($templateBodySeoTitle.val() || '').trim();
            const templateBodySeoDescription = String($templateBodySeoDescription.val() || '').trim();
            const mode = getSelectionMode();
            const createMode = mode === 'create_new';
            const createTopics = createMode ? collectCreateTopics() : [];
            const filters = createMode ? [] : normalizeFilters();
            const lengthOptionId = Number($lengthOption.val() || 0);
            const model = getEnhancedSelectValue($modelSelect) || String(state.defaultModel || 'auto');
            const varyLength = $varyLength.is(':checked') ? 1 : 0;
            const styleLanguage = String($styleLanguage.val() || (state.schema && state.schema.settings && state.schema.settings.default_language) || 'auto');
            const styleTone = String($styleTone.val() || (state.schema && state.schema.settings && state.schema.settings.default_tone) || 'neutral');
            const aiFields = scenarioSupportsMultiFields(scenario) ? collectAiFieldsForRun() : [];
            const staticFields = scenarioSupportsMultiFields(scenario) ? collectStaticFieldsForRun() : [];

            if (!postType) {
                setRunStatus(jsT('Выберите тип записей.'), true);
                switchStep(2);
                return;
            }
            if (!targetField && !scenarioSupportsItemsPerPost(scenario) && !scenarioSupportsMultiFields(scenario)) {
                setRunStatus(jsT('Выберите целевое поле.'), true);
                switchStep(2);
                return;
            }
            if (!wooRatingRange.valid) {
                setRunStatus(wooRatingRange.message || jsT('Проверьте диапазон рейтинга.'), true);
                return;
            }
            if (lengthOptionId <= 0 && !scenarioSupportsMultiFields(scenario)) {
                setRunStatus(jsT('Выберите диапазон длины текста.'), true);
                return;
            }
            if (scenario === 'seo_tags') {
                if (!templateBodySeoTitle || !templateBodySeoDescription) {
                    setRunStatus(jsT('Заполните шаблоны для SEO title и SEO description.'), true);
                    return;
                }
            } else if (scenarioSupportsMultiFields(scenario)) {
                if (createMode && createTopics.length <= 0) {
                    setRunStatus(jsT('Добавьте хотя бы одну тему для создания.'), true);
                    switchStep(2);
                    return;
                }
                const hasEnabledAiWithoutPrompt = aiFields.some(function (field) {
                    return !!(field && field.enabled && String(field.prompt || '').trim() === '');
                });
                if (hasEnabledAiWithoutPrompt) {
                    setRunStatus(jsT('Для включённых AI-полей заполните промпт.'), true);
                    return;
                }
                const hasEnabledAi = aiFields.some(function (field) {
                    return !!(field && field.enabled && String(field.prompt || '').trim() !== '');
                });
                const hasEnabledStatic = hasEnabledStaticFields(staticFields);
                if (!hasEnabledAi && !hasEnabledStatic) {
                    setRunStatus(jsT('Выберите хотя бы одно AI или static поле.'), true);
                    return;
                }
            } else if (!templateBody) {
                setRunStatus(jsT('Шаблон пустой. Заполните текст.'), true);
                return;
            }

            setButtonLoading($button, true);
            setRunStatus(jsT('Генерируем пример...'), false, {
                type: 'loading',
                key: 'example-generate',
                force: true
            });

            const payload = {
                action: 'ucg_wizard_example',
                nonce: ucgAdmin.nonce,
                scenario: scenario,
                post_type: postType,
                target_field: targetField,
                model: model,
                template_body: templateBody,
                template_body_seo_title: templateBodySeoTitle,
                template_body_seo_description: templateBodySeoDescription,
                length_option_id: lengthOptionId,
                vary_length: varyLength,
                rating_min: wooRatingRange.ratingMin,
                rating_max: wooRatingRange.ratingMax,
                style_language: styleLanguage,
                style_tone: styleTone,
                selection_mode: mode,
                create_topics: createTopics.join('\n'),
                selected_ids: JSON.stringify(createMode ? [] : Array.from(state.selectedIds)),
                filters: JSON.stringify(filters)
            };
            if (scenarioSupportsMultiFields(scenario)) {
                payload.ai_fields = JSON.stringify(aiFields);
                payload.static_fields = JSON.stringify(staticFields);
            } else if (scenario === 'seo_tags') {
                payload.ai_fields = JSON.stringify(
                    buildSeoAiFieldsForRun(templateBodySeoTitle, templateBodySeoDescription, lengthOptionId)
                );
            }

            $.post(ucgAdmin.ajaxUrl, payload).done(function (response) {
                if (!response.success) {
                    const msg = response.data && response.data.message ? response.data.message : jsT('Не удалось сгенерировать пример.');
                    setRunStatus(msg, true, { type: 'error', key: 'example-generate', force: true });
                    return;
                }
                const data = response.data || {};
                const preview = data.preview;
                const spent = Number(data.credits_spent || 0);
                const remaining = Number(data.credits_remaining || 0);
                if ($exampleOutput.length) {
                    renderExampleOutput(preview);
                }
                if ($exampleCredits.length) {
                    $exampleCredits.text(
                        jsT('Списано: ~') + formatCreditsValue(spent, 2) + ' ' + jsT('кр.') +
                        jsT(' • Осталось: ') + formatCreditsValue(remaining, 2) + ' ' + jsT('кр.')
                    );
                }
                if ($exampleWrap.length) {
                    $exampleWrap.show();
                }
                // Refresh balance if the header widget exists.
                if ($('.ucg-balance-value').length) {
                    fetchBalance(false, $('#ucg-refresh-balance'));
                }
                const spentLabel = jsT('Списано ~') + formatCreditsValue(spent, 2) + ' ' + jsT('кр.');
                setRunStatus(jsT('Пример готов.'), false, {
                    type: 'success',
                    key: 'example-generate',
                    detail: spent > 0 ? spentLabel : '',
                    force: true
                });
                if (spent > 0) {
                    setRunStatus(spentLabel, false, {
                        type: 'info',
                        key: 'credits-spent',
                        detail: jsT('Остаток: ') + formatCreditsValue(remaining, 2) + ' ' + jsT('кр.'),
                        force: true
                    });
                }
            }).fail(function () {
                setRunStatus(jsT('AJAX ошибка при генерации примера.'), true, { type: 'error', key: 'example-generate', force: true });
            }).always(function () {
                setButtonLoading($button, false);
            });
        }

        function bindEvents() {
            const $advancedToggle = $('#ucg-advanced-toggle');
            const $advancedBody = $('#ucg-advanced-body');
            const $advancedWrap = $('.ucg-advanced');

            if ($toastStack.length) {
                $toastStack.on('mouseenter', pauseToastTimers);
                $toastStack.on('mouseleave', resumeToastTimers);
                $toastStack.on('focusin', pauseToastTimers);
                $toastStack.on('focusout', function () {
                    window.setTimeout(function () {
                        if (!$toastStack.find(':focus').length) {
                            resumeToastTimers();
                        }
                    }, 0);
                });
                $toastStack.on('click', '.ucg-toast__close', function () {
                    const toastId = String($(this).closest('.ucg-toast').attr('data-toast-id') || '');
                    if (!toastId) {
                        return;
                    }
                    closeToastById(toastId, false);
                });
            }

            if ($advancedToggle.length && $advancedBody.length) {
                const setAdvancedOpen = function (isOpen) {
                    const next = !!isOpen;
                    $advancedWrap.toggleClass('is-open', next);
                    $advancedToggle.attr('aria-expanded', next ? 'true' : 'false');
                    $advancedBody.prop('hidden', !next).toggle(next);
                };

                // Initial state must always be closed by default to avoid UI desync.
                setAdvancedOpen(false);

                $advancedToggle.on('click', function () {
                    const isOpen = $advancedWrap.hasClass('is-open');
                    if (isOpen) {
                        setAdvancedOpen(false);
                        return;
                    }
                    setAdvancedOpen(true);
                });
            }

            $(document).on('click', '#ucg-run-summary-change-model', function () {
                if (!$modelSelect.length) {
                    return;
                }
                switchStep(3);
                const modelElement = $modelSelect.get(0);
                if (modelElement && modelElement.tomselect) {
                    modelElement.tomselect.focus();
                    modelElement.tomselect.open();
                    return;
                }
                const $tsControl = $modelSelect.closest('.ts-wrapper').find('.ts-control');
                if ($tsControl.length) {
                    $tsControl.trigger('click');
                    return;
                }
                $modelSelect.trigger('focus');
            });

            if ($itemsPerPost.length) {
                $itemsPerPost.on('change keyup', function () {
                    const scenario = getScenario();
                    if (!scenarioSupportsItemsPerPost(scenario)) {
                        return;
                    }
                    const normalized = normalizeItemsPerPost($(this).val());
                    $(this).val(String(normalized));
                    renderRunSummary();
                });
            }

            if ($wooRatingMin.length && $wooRatingMax.length) {
                const normalizeWooRatingInputs = function () {
                    const scenario = getScenario();
                    if (!scenarioSupportsWooRatingRange(scenario)) {
                        return;
                    }
                    let ratingMin = normalizeWooRatingValue($wooRatingMin.val());
                    let ratingMax = normalizeWooRatingValue($wooRatingMax.val());
                    if (ratingMin > ratingMax) {
                        const tmp = ratingMin;
                        ratingMin = ratingMax;
                        ratingMax = tmp;
                    }
                    $wooRatingMin.val(String(ratingMin));
                    $wooRatingMax.val(String(ratingMax));
                };
                $wooRatingMin.on('change', normalizeWooRatingInputs);
                $wooRatingMax.on('change', normalizeWooRatingInputs);
            }

            $('#ucg-step-1-next').on('click', function () {
                const scenario = getScenario();
                if (!scenario) {
                    setRunStatus(jsT('Выберите сценарий генерации.'), true);
                    return;
                }
                switchStep(2);
                if (!isCreateNewMode() && state.total === 0) {
                    previewPosts(1, $('#ucg-preview-posts'));
                }
            });

            $('#ucg-step-2-back').on('click', function () {
                switchStep(1);
            });

            $('#ucg-step-2-next').on('click', function () {
                const scenario = getScenario();
                const postType = scenarioRequiresProductPostType(scenario) ? 'product' : String($postType.val() || '');
                const targetField = String($targetField.val() || '');
                const mode = getSelectionMode();
                const planned = getPlannedCount();
                if (!postType) {
                    setRunStatus(jsT('Выберите тип записей.'), true);
                    return;
                }
                if (!targetField && !scenarioSupportsItemsPerPost(scenario) && !scenarioSupportsMultiFields(scenario)) {
                    setRunStatus(jsT('Выберите целевое поле.'), true);
                    return;
                }
                if (mode === 'create_new') {
                    if (planned <= 0) {
                        setRunStatus(jsT('Добавьте хотя бы одну тему для создания.'), true);
                        return;
                    }
                    switchStep(3);
                    return;
                }
                if (mode === 'selected' && planned <= 0) {
                    setRunStatus(jsT('Выберите записи вручную или переключитесь на режим "все найденные".'), true);
                    return;
                }
                if (mode === 'filtered' && planned <= 0) {
                    setRunStatus(jsT('Сначала загрузите записи.'), true);
                    return;
                }
                switchStep(3);
            });

            $('#ucg-step-3-back').on('click', function () {
                switchStep(2);
            });

            $(document).on('click', '.ucg-stepper__item', function () {
                const target = Number($(this).data('step-target') || 1);
                if (target <= state.step + 1) {
                    switchStep(target);
                }
            });

            $postType.on('change', function () {
                const scenario = getScenario();
                let postType = String($(this).val() || '');
                if (scenarioRequiresProductPostType(scenario) && $postType.find('option[value="product"]').length) {
                    postType = 'product';
                    setPostTypeValueSilently(postType);
                }
                const $btn = $('#ucg-step-1-next');
                refreshSchema(postType, function () {
                    state.page = 1;
                    state.total = 0;
                    state.totalPages = 1;
                    $previewBody.html(jsT('<tr><td colspan="5">Загружаем записи...</td></tr>'));
                    updatePagination();
                    setRunStatus('', false);
                }, $btn, false);
            });

            $scenarioInputs.on('change', function () {
                const scenario = getScenario();
                if (scenarioSupportsCreateNewMode(scenario) && $runTargetMode.length) {
                    $runTargetMode.prop('checked', false);
                    $runTargetMode.filter('[value="create_new"]').prop('checked', true);
                }
                let postType = applyPostTypeSelectionVisibility(scenario) || String($postType.val() || '');
                if (scenario === 'post_fields' && $postType.find('option[value="post"]').length) {
                    postType = 'post';
                    setPostTypeValueSilently(postType);
                }
                const $btn = $('#ucg-step-1-next');
                refreshSchema(postType, function () {
                    state.page = 1;
                    state.total = 0;
                    state.totalPages = 1;
                    $previewBody.html(jsT('<tr><td colspan="5">Загружаем записи...</td></tr>'));
                    updatePagination();
                    setRunStatus('', false);
                }, $btn, false);
            });

            $lengthOption.on('change', function () {
                renderGenerationModels();
            });

            $modelSelect.on('change', function () {
                updateModelHint();
            });

            $(document).on('click', '.ucg-ai-field-toggle', function (event) {
                event.preventDefault();
                event.stopPropagation();
                toggleAiFieldRow($(this).closest('.ucg-ai-field-row'));
            });

            $(document).on('click', '.ucg-ai-field-row__head', function (event) {
                const $target = $(event.target);
                if ($target.closest('input, button, select, textarea, a').length) {
                    return;
                }
                toggleAiFieldRow($(this).closest('.ucg-ai-field-row'));
            });

            $(document).on('change', '.ucg-ai-field-enabled, .ucg-ai-field-length, .ucg-ai-field-image-model, .ucg-ai-field-aspect-ratio, .ucg-ai-field-image-size, .ucg-ai-field-images-count', function () {
                refreshAiFieldRowsUi();
                renderRunSummary();
            });

            $(document).on('keyup', '.ucg-ai-field-prompt', function () {
                renderRunSummary();
            });

            $(document).on('input', '.ucg-ai-field-images-count', function () {
                const parsed = Number($(this).val() || 1);
                const normalized = Number.isFinite(parsed) ? Math.max(1, Math.min(8, Math.round(parsed))) : 1;
                $(this).val(String(normalized));
                renderRunSummary();
            });

            $(document).on('change keyup', '.ucg-static-field-enabled, .ucg-static-field-value, .ucg-static-field-value-check', function () {
                refreshStaticFieldRowsUi();
                renderRunSummary();
            });

            $(document).on('focus', '.ucg-ai-field-prompt', function () {
                $activeTemplateTextarea = $(this);
            });

            $wizardTokenSearch.on('input', function () {
                renderWizardTokens();
            });

            $templateSelect.on('change', function () {
                loadTemplate($(this).val());
            });

            $saveTemplateChanges.on('change', function () {
                updateTemplateMode();
            });

            $('#ucg-add-filter-row').on('click', function () {
                renderFilterRow();
            });

            $(document).on('click', '.ucg-remove-filter-row', function () {
                $(this).closest('.ucg-filter-row-item').remove();
            });

            $(document).on('change', '.ucg-filter-field', function () {
                renderFilterOperatorOptions($(this).closest('.ucg-filter-row-item'));
            });

            $(document).on('change', '.ucg-filter-operator', function () {
                updateFilterRowInputVisibility($(this).closest('.ucg-filter-row-item'));
            });

            $('#ucg-preview-posts').on('click', function () {
                state.page = 1;
                previewPosts(1, $(this));
            });

            $(document).on('click', '.ucg-page-btn', function () {
                const page = Number($(this).data('page') || 1);
                if (page < 1 || page === state.page) {
                    return;
                }
                previewPosts(page, $(this));
            });

            $('#ucg-preview-select-page').on('change', function () {
                const checked = $(this).is(':checked');
                state.currentItems.forEach(function (item) {
                    const id = Number(item && item.id ? item.id : 0);
                    if (!id) {
                        return;
                    }
                    if (checked) {
                        state.selectedIds.add(id);
                    } else {
                        state.selectedIds.delete(id);
                    }
                });
                $('.ucg-preview-checkbox').prop('checked', checked);
                updateSelectedCount();
                updateSummary();
            });

            $(document).on('change', '.ucg-preview-checkbox', function () {
                const id = Number($(this).val());
                if (!id) {
                    return;
                }
                if ($(this).is(':checked')) {
                    state.selectedIds.add(id);
                } else {
                    state.selectedIds.delete(id);
                }
                updateSelectedCount();
                syncPageCheckbox();
                updateSummary();
            });

            $selectionMode.on('change', function () {
                updateSummary();
            });

            $runTargetMode.on('change', function () {
                applyStep2ModeVisibility();
                if (isCreateNewMode()) {
                    setRunStatus('', false);
                    updateSummary();
                    return;
                }
                if (state.step === 2 && state.total === 0) {
                    previewPosts(1, $('#ucg-preview-posts'));
                    return;
                }
                updateSummary();
            });

            $createTopics.on('change keyup', function () {
                updateSummary();
            });

            $('#ucg-start-run').on('click', function () {
                startRun($(this));
            });

            $('#ucg-generate-example').on('click', function () {
                generateExample($(this));
            });

            $(document).on('click', '#ucg-wizard-tokens .ucg-token-btn', function () {
                const token = $(this).data('token');
                if (!token) {
                    return;
                }
                insertAtCursor(activeTemplateInput(), String(token));
            });

            $(document).on('dragstart', '#ucg-wizard-tokens .ucg-token-btn', function (event) {
                const token = String($(this).data('token') || '');
                if (!token) {
                    return;
                }
                if (event.originalEvent && event.originalEvent.dataTransfer) {
                    event.originalEvent.dataTransfer.setData('text/plain', token);
                }
            });

            $(document).on('focus', '.ucg-wizard-template-input', function () {
                $activeTemplateTextarea = $(this);
            });

            $(document).on('dragover', '.ucg-wizard-template-input', function (event) {
                event.preventDefault();
            });

            $(document).on('drop', '.ucg-wizard-template-input', function (event) {
                event.preventDefault();
                if (!event.originalEvent || !event.originalEvent.dataTransfer) {
                    return;
                }
                const token = event.originalEvent.dataTransfer.getData('text/plain');
                if (!token) {
                    return;
                }
                const $targetInput = $(this);
                if ($targetInput.length) {
                    $activeTemplateTextarea = $targetInput;
                }
                insertAtCursor(activeTemplateInput(), token);
            });

            $(window).on('beforeunload', function () {
                clearRunMonitorTimer();
            });
        }

        renderTargetFields();
        renderTextLengthOptions();
        setScenarioCardState();
        renderGenerationModels();
        renderTemplates();
        renderWizardTokens();
        applyStyleDefaultsFromSchema();
        clearFilters();
        updateSelectedCount();
        updatePagination();
        updateSummary();
        bindEvents();
        const hasSchemaTargetFields = Array.isArray(state.schema.target_fields) && state.schema.target_fields.length > 0;
        const hasSchemaLengthOptions = Array.isArray(state.schema.text_length_options) && state.schema.text_length_options.length > 0;
        if (!hasSchemaTargetFields || !hasSchemaLengthOptions) {
            refreshSchema(String($postType.val() || ''), function () {
                const prefillHandled = applyWizardPrefill();
                if (!prefillHandled) {
                    if (scenarioSupportsCreateNewMode(getScenario()) && $runTargetMode.length) {
                        $runTargetMode.prop('checked', false);
                        $runTargetMode.filter('[value="create_new"]').prop('checked', true);
                    }
                    applyStep2ModeVisibility();
                    if (!isCreateNewMode()) {
                        previewPosts(1, $('#ucg-preview-posts'));
                    } else {
                        updateSummary();
                    }
                }
            }, null, true);
            return;
        }
        const prefillHandled = applyWizardPrefill();
        if (!prefillHandled) {
            if (scenarioSupportsCreateNewMode(getScenario()) && $runTargetMode.length) {
                $runTargetMode.prop('checked', false);
                $runTargetMode.filter('[value="create_new"]').prop('checked', true);
            }
            applyStep2ModeVisibility();
            if (!isCreateNewMode()) {
                previewPosts(1, $('#ucg-preview-posts'));
            } else {
                updateSummary();
            }
        }
    }

    function initRunProgressPage() {
        const $page = $('#ucg-run-progress-page');
        if (!$page.length) {
            return;
        }

        const runId = Number($page.data('runId') || $page.attr('data-run-id') || 0);
        if (!runId) {
            return;
        }

        const $title = $('#ucg-run-monitor-title');
        const $statusChip = $('#ucg-run-monitor-status');
        const $progressBar = $('#ucg-run-progress-bar');
        const $stats = $('#ucg-run-monitor-stats');
        const $log = $('#ucg-run-log');
        const $status = $('#ucg-run-progress-status');
        const $reviewLink = $('#ucg-run-review-link');
        const $actions = $('#ucg-run-progress-actions');
        const $continueBtn = $('#ucg-run-continue');
        const $continueHint = $('#ucg-run-continue-hint');
        let timer = null;
        let inFlight = false;
        let lastKnownBatch = 0;

        function clearTimer() {
            if (timer) {
                window.clearTimeout(timer);
                timer = null;
            }
        }

        function setStatus(message, isError) {
            if (!$status.length) {
                return;
            }
            setInlineStatusMessage($status, message, !!isError, 5000);
        }

        function setChipStatus(status, label) {
            if (!$statusChip.length) {
                return;
            }

            const normalized = String(status || '');
            const statusClassMap = {
                queued: 'ucg-status-queued',
                running: 'ucg-status-running',
                completed: 'ucg-status-completed',
                failed: 'ucg-status-failed'
            };

            $statusChip
                .removeClass('ucg-status-queued ucg-status-running ucg-status-completed ucg-status-failed')
                .addClass(statusClassMap[normalized] || 'ucg-status-running')
                .text(label || normalized || jsT('—'));
        }

        function renderLog(logs) {
            if (!$log.length) {
                return;
            }

            if (!Array.isArray(logs) || !logs.length) {
                $log.html(jsT('<div class="ucg-muted">Логи появятся после обработки первых записей.</div>'));
                return;
            }

            function formatItemStatusLabel(entry) {
                const normalized = String(entry && entry.status ? entry.status : '').trim().toLowerCase();
                const fallbackLabel = entry && entry.status_label ? String(entry.status_label).trim() : '';
                if (normalized === 'approved') {
                    return jsT('Применено');
                }
                if (normalized === 'generated') {
                    return jsT('Сгенерировано (ожидает проверки)');
                }
                if (normalized === 'failed') {
                    return jsT('Ошибка');
                }
                if (normalized === 'queued') {
                    return jsT('В очереди');
                }
                if (normalized === 'running') {
                    return jsT('Обрабатывается');
                }
                const fallbackNormalized = fallbackLabel.toLowerCase();
                if (fallbackNormalized === jsT('Одобрено').toLowerCase() || fallbackNormalized === 'approved') {
                    return jsT('Применено');
                }
                return fallbackLabel || jsT('Статус');
            }

            const lines = logs.map(function (entry) {
                const postId = Number(entry && entry.post_id ? entry.post_id : 0);
                const statusLabel = formatItemStatusLabel(entry);
                const error = entry && entry.error_message ? String(entry.error_message) : '';
                const timestamp = entry && entry.updated_at ? String(entry.updated_at) : '';
                let text = timestamp ? ('[' + timestamp + '] ') : '';
                text += jsT('Запись #') + postId + ': ' + statusLabel;
                if (error) {
                    text += jsT(' — ') + error;
                }
                return '<li>' + escapeHtml(text) + '</li>';
            }).join('');

            $log.html('<ul class="ucg-run-log-list">' + lines + '</ul>');
        }

        function renderState(data) {
            const run = data.run || {};
            const currentRunId = Number(run.id || runId);
            const progress = Math.max(0, Math.min(100, Number(run.progress || 0)));
            const total = Number(run.total_items || 0);
            const processed = Number(run.processed_items || 0);
            const queued = Number(run.queued_items || 0);
            const success = Number(run.success_items || 0);
            const failed = Number(run.failed_items || 0);
            const effectiveBatch = Number(run.effective_batch_size || 0);
            if (effectiveBatch > 0) {
                lastKnownBatch = effectiveBatch;
            }

            if ($title.length) {
                $title.text(jsT('Запуск #') + currentRunId);
            }
            setChipStatus(run.status, run.status_label);
            $progressBar.css('width', progress + '%');
            const batchSuffix = lastKnownBatch > 0 ? (jsT(' • шаг ') + lastKnownBatch) : '';
            $stats.text(progress + jsT('% • обработано ') + processed + jsT(' из ') + total + jsT(' • в очереди ') + queued + jsT(' • ошибок ') + failed + jsT(' • готово ') + success + batchSuffix);
            renderLog(Array.isArray(data.logs) ? data.logs : []);

            const issue = data.issue && typeof data.issue === 'object' ? data.issue : null;
            if (issue && (issue.message || issue.type)) {
                if ($actions.length) {
                    $actions.show();
                }
                if ($continueHint.length) {
                    const msg = String(issue.message || jsT('Проблема при генерации. Попробуйте продолжить меньшими шагами.'));
                    $continueHint.text(msg);
                }
                setStatus(jsT('Похоже, есть ограничения/таймаут. Нажмите «Продолжить» — продолжим меньшими шагами.'), true);
            } else {
                if ($actions.length) {
                    $actions.hide();
                }
                if ($continueHint.length) {
                    $continueHint.text('');
                }
            }
        }

        function processStep(forceSmaller, done) {
            $.post(ucgAdmin.ajaxUrl, {
                action: 'ucg_process_now',
                nonce: ucgAdmin.nonce,
                run_id: runId,
                force_smaller: forceSmaller ? 1 : 0
            }).done(function (response) {
                if (!response || !response.success) {
                    const msg = response && response.data && response.data.message ? response.data.message : jsT('Не удалось обработать очередь.');
                    setStatus(msg, true);
                    if (typeof done === 'function') {
                        done({ ok: false, recommended_poll_ms: 5000 });
                    }
                    return;
                }
                const data = response.data || {};
                if (data && typeof data.effective_batch_size !== 'undefined') {
                    lastKnownBatch = Number(data.effective_batch_size || lastKnownBatch || 0);
                }
                if (data.issue && data.issue.message) {
                    setStatus(String(data.issue.message), true);
                }
                if (typeof done === 'function') {
                    done({ ok: true, recommended_poll_ms: Number(data.recommended_poll_ms || 1500) });
                }
            }).fail(function () {
                setStatus(jsT('AJAX ошибка при обработке очереди.'), true);
                if (typeof done === 'function') {
                    done({ ok: false, recommended_poll_ms: 5000 });
                }
            });
        }

        function pollStatus(nextDelay) {
            $.post(ucgAdmin.ajaxUrl, {
                action: 'ucg_run_status',
                nonce: ucgAdmin.nonce,
                run_id: runId
            }).done(function (response) {
                if (!response.success) {
                    const msg = response.data && response.data.message ? response.data.message : jsT('Не удалось обновить прогресс.');
                    setStatus(msg, true);
                    clearTimer();
                    timer = window.setTimeout(poll, 5000);
                    return;
                }

                const data = response.data || {};
                renderState(data);

                if (data.review_url) {
                    $reviewLink.attr('href', String(data.review_url));
                }

                if (data.is_finished) {
                    setStatus(jsT('Запуск завершён. Можно открыть проверку результатов.'), false);
                    $reviewLink.show();
                    clearTimer();
                    return;
                }

                const delay = typeof nextDelay === 'number' && nextDelay > 0 ? nextDelay : 1500;
                setStatus(jsT('Генерация в процессе. Страница обновляется автоматически.'), false);
                clearTimer();
                timer = window.setTimeout(poll, delay);
            }).fail(function () {
                setStatus(jsT('AJAX ошибка при обновлении прогресса.'), true);
                clearTimer();
                timer = window.setTimeout(poll, 5000);
            });
        }

        function poll() {
            if (inFlight) {
                clearTimer();
                timer = window.setTimeout(poll, 1500);
                return;
            }
            inFlight = true;
            processStep(false, function (step) {
                pollStatus(step && step.recommended_poll_ms ? step.recommended_poll_ms : 1500);
                inFlight = false;
            });
        }

        if ($continueBtn.length) {
            $continueBtn.on('click', function () {
                if (inFlight) {
                    return;
                }
                inFlight = true;
                setButtonLoading($continueBtn, true);
                processStep(true, function () {
                    setButtonLoading($continueBtn, false);
                    pollStatus(1500);
                    inFlight = false;
                });
            });
        }

        poll();

        $(window).on('beforeunload', function () {
            clearTimer();
        });
    }

    $('#ucg-save-api-key').on('click', function () {
        saveApiKey($(this));
    });

    $('#ucg-delete-api-key').on('click', function () {
        deleteApiKey($(this));
    });

    $('#ucg-refresh-balance').on('click', function () {
        fetchBalance(true, $(this));
    });

    $('#ucg-select-all-review').on('change', function () {
        $('.ucg-review-checkbox').prop('checked', $(this).is(':checked'));
    });

    $(document).on('submit', '.ucg-wrap form', function () {
        const $form = $(this);
        let $submit = $form.find('button[type="submit"]:focus');
        if (!$submit.length) {
            $submit = $form.find('button[type="submit"]').first();
        }
        setButtonLoading($submit, true);
    });

    $(document).on('click', '.ucg-wrap a.button', function () {
        const $button = $(this);
        if ($button.attr('target') === '_blank') {
            return;
        }
        setButtonLoading($button, true);
        setTimeout(function () {
            setButtonLoading($button, false);
        }, 1200);
    });

    $(document).on('click', '.ucg-wrap .button', function () {
        const $button = $(this);
        if ($button.hasClass('is-loading')) {
            return;
        }
        $button.addClass('is-pressed');
        setTimeout(function () {
            $button.removeClass('is-pressed');
        }, 160);
    });

    initEnhancedSelects($(document));
    initTemplatesPage();
    initSettingsPage();
    initReviewModal();
    initLogsPage();
    initGenerateWizard();
    initRunProgressPage();
    initTimedStatusMessages();

    const currentKeyText = String($('#ucg-current-key').text() || '').trim();
    setApiKeyUiState(currentKeyText !== '' && currentKeyText !== jsT('не задан'), currentKeyText);

    if ($('.ucg-balance-value').length) {
        fetchBalance(false, $('#ucg-refresh-balance'));
    }
});
