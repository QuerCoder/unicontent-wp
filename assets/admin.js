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

    function setApiStatus(message, isError) {
        const $status = $('#ucg-api-status');
        if (!$status.length) {
            return;
        }

        const cls = isError ? 'ucg-status-message ucg-status-message--error' : 'ucg-status-message ucg-status-message--ok';
        $status.html('<div class="' + cls + '">' + escapeHtml(message) + '</div>');
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

    function renderTokenButtons($container, tokens) {
        if (!$container.length) {
            return;
        }

            if (!Array.isArray(tokens) || !tokens.length) {
                $container.html(jsT('<p class="ucg-muted">Переменные не найдены.</p>'));
                return;
            }

        let html = '';
        tokens.forEach(function (item) {
            const token = item && item.token ? String(item.token) : '';
            const label = item && item.label ? String(item.label) : token;
            if (!token) {
                return;
            }
            html += '<button type="button" class="ucg-token-btn" draggable="true" data-token="' + escapeHtml(token) + '" title="' + escapeHtml(label) + '">' + escapeHtml(token) + '</button>';
        });

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
                config.searchField = ['text'];
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
                    config.searchField = searchEnabled ? [searchField] : [];
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

            new window.TomSelect(element, config);
        });
    }

    function initTemplatesPage() {
        const $templatePostType = $('#ucg-template-post-type');
        const $templateScenario = $('#ucg-template-scenario');
        const $tokensContainer = $('#ucg-template-tokens');
        const $templateBody = $('#ucg-template-body');
        const $templateBlockRows = $('#ucg-template-block-rows');
        const $addPromptBlock = $('#ucg-add-prompt-block');
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
        let $activeTemplateInput = $templateBody;

        if (
            !$tokensContainer.length &&
            !$templateBody.length &&
            !$templateBlockRows.length &&
            !$readyTypeFilter.length &&
            !$readyCards.length
        ) {
            return;
        }

        function activeTemplateEditorInput() {
            if ($activeTemplateInput && $activeTemplateInput.length && $activeTemplateInput.is(':visible')) {
                return $activeTemplateInput;
            }
            const $firstBlockInput = $templateBlockRows.find('.ucg-template-block-input').first();
            if ($firstBlockInput.length) {
                return $firstBlockInput;
            }
            return $templateBody;
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
            const $rows = $templateBlockRows.find('.ucg-template-block-row');
            if (!$rows.length) {
                if (scenario === 'seo_tags') {
                    addPromptBlockRow({ key: 'seo_title', label: jsT('SEO title') });
                    addPromptBlockRow({ key: 'seo_description', label: jsT('SEO description') });
                } else {
                    addPromptBlockRow({ key: 'main', label: jsT('Основной промпт') });
                }
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

            if (scenario === 'seo_tags' && (currentKey === '' || currentKey === 'main' || currentKey === 'block_1')) {
                $templateBlockRows.empty();
                addPromptBlockRow({ key: 'seo_title', label: jsT('SEO title') });
                addPromptBlockRow({ key: 'seo_description', label: jsT('SEO description') });
                return;
            }

            if (scenario !== 'seo_tags' && (currentKey === '' || currentKey === 'seo_title')) {
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
                renderTokenButtons($tokensContainer, Array.isArray(data.tokens) ? data.tokens : []);
            });
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
            if (!prompt || !$templateBody.length) {
                return;
            }

            const body = prompt && prompt.body ? String(prompt.body) : '';
            if (!body) {
                return;
            }

            const $firstBlockInput = $templateBlockRows.find('.ucg-template-block-input').first();
            if ($firstBlockInput.length) {
                $firstBlockInput.val(body).trigger('input');
                $activeTemplateInput = $firstBlockInput;
            } else if ($templateBody.length) {
                $templateBody.val(body).trigger('input');
                $activeTemplateInput = $templateBody;
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

        if ($templateScenario.length) {
            $templateScenario.on('change', function () {
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

        $(document).on('click', '.ucg-remove-prompt-block', function () {
            const $row = $(this).closest('.ucg-template-block-row');
            if ($row.length) {
                $row.remove();
            }
            applyScenarioPresetIfNeeded();
        });

        $(document).on('focus', '#ucg-template-body, .ucg-template-block-input', function () {
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

        $(document).on('dragover', '#ucg-template-body, .ucg-template-block-input', function (event) {
            event.preventDefault();
        });

        $(document).on('drop', '#ucg-template-body, .ucg-template-block-input', function (event) {
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

        initEnhancedSelects($templatePostType);
        initEnhancedSelects($templateScenario);
        initEnhancedSelects($readyTypeFilter);
        initEnhancedSelects($libraryCategory);
        initEnhancedSelects($libraryType);
        initEnhancedSelects($libraryPrompt);

        const readyTypeElement = $readyTypeFilter.length ? $readyTypeFilter.get(0) : null;
        if (readyTypeElement && readyTypeElement.tomselect) {
            readyTypeElement.tomselect.on('change', applyReadyTypeFilter);
        }

        applyReadyTypeFilter();
        renderLibraryPromptSelect();
        applyScenarioPresetIfNeeded();
        const $initialBlockInput = $templateBlockRows.find('.ucg-template-block-input').first();
        if ($initialBlockInput.length) {
            $activeTemplateInput = $initialBlockInput;
        }
    }

    function initSettingsPage() {
        const $batchInput = $('#ucg-batch-size-input');
        const $saveBatchButton = $('#ucg-save-batch-size');
        const $generationMode = $('#ucg-generation-mode');
        if (!$batchInput.length || !$saveBatchButton.length) {
            return;
        }

        initEnhancedSelects($generationMode);

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

    function initGenerateWizard() {
        const $wizard = $('#ucg-wizard');
        if (!$wizard.length) {
            return;
        }

        const initialSchema = parseJsonScript('#ucg-wizard-initial', {});
        if (!initialSchema || typeof initialSchema !== 'object') {
            return;
        }

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
        };

        const $scenarioCards = $('#ucg-wizard-scenario-picker');
        const $scenarioInputs = $('input[name="ucg-wizard-scenario"]');
        const $postType = $('#ucg-wizard-post-type');
        const $targetField = $('#ucg-wizard-target-field');
        const $targetFieldLabel = $('#ucg-wizard-target-field-label');
        const $templateSelect = $('#ucg-wizard-template');
        const $templateName = $('#ucg-wizard-template-name');
        const $templateNameWrap = $('#ucg-template-name-wrap');
        const $saveTemplateLabel = $('#ucg-save-template-label');
        const $lengthOption = $('#ucg-wizard-length-option');
        const $lengthControlsWrap = $('#ucg-length-controls-wrap');
        const $seoGuidelines = $('#ucg-seo-guidelines');
        const $modelSelect = $('#ucg-wizard-model');
        const $modelHint = $('#ucg-wizard-model-hint');
        const $unitHint = $('#ucg-wizard-unit-hint');
        const $varyLength = $('#ucg-wizard-vary-length');
        const $varyLengthHint = $('#ucg-wizard-vary-length-hint');
        const $templateBodyStandardWrap = $('#ucg-template-body-standard-wrap');
        const $templateBodySeoWrap = $('#ucg-template-body-seo-wrap');
        const $templateBody = $('#ucg-wizard-template-body');
        const $templateBodySeoTitle = $('#ucg-wizard-template-body-seo-title');
        const $templateBodySeoDescription = $('#ucg-wizard-template-body-seo-description');
        const $publishDateRangeWrap = $('#ucg-publish-date-range-wrap');
        const $publishDateFrom = $('#ucg-wizard-publish-date-from');
        const $publishDateTo = $('#ucg-wizard-publish-date-to');
        const $wizardTokenSearch = $('#ucg-wizard-token-search');
        const $tokens = $('#ucg-wizard-tokens');
        const $filterRows = $('#ucg-filter-rows');
        const $previewBody = $('#ucg-preview-tbody');
        const $previewSummary = $('#ucg-preview-summary');
        const $previewPagination = $('#ucg-preview-pagination');
        const $selectedCount = $('#ucg-selected-count');
        const $runResult = $('#ucg-run-result');
        const $runSummary = $('#ucg-run-summary');
        const $runMonitor = $('#ucg-run-monitor');
        const $runMonitorTitle = $('#ucg-run-monitor-title');
        const $runMonitorStatus = $('#ucg-run-monitor-status');
        const $runProgressBar = $('#ucg-run-progress-bar');
        const $runMonitorStats = $('#ucg-run-monitor-stats');
        const $runLog = $('#ucg-run-log');
        const $runReviewLink = $('#ucg-run-review-link');
        const $selectionMode = $('input[name="ucg-selection-mode"]');
        const $saveTemplateChanges = $('#ucg-save-template-changes');
        let $activeTemplateTextarea = $templateBody;

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

        function activeTemplateInput() {
            if ($activeTemplateTextarea && $activeTemplateTextarea.length && $activeTemplateTextarea.is(':visible')) {
                return $activeTemplateTextarea;
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
            const isSeo = scenario === 'seo_tags';
            if ($templateBodyStandardWrap.length) {
                $templateBodyStandardWrap.toggle(!isSeo);
            }
            if ($templateBodySeoWrap.length) {
                $templateBodySeoWrap.prop('hidden', !isSeo).toggle(isSeo);
            }
            if (isSeo) {
                $activeTemplateTextarea = $templateBodySeoTitle;
            } else {
                $activeTemplateTextarea = $templateBody;
            }
            if ($lengthControlsWrap.length) {
                $lengthControlsWrap.toggle(!isSeo);
            }
            if ($seoGuidelines.length) {
                $seoGuidelines.prop('hidden', !isSeo).toggle(isSeo);
            }
            togglePublishDateRangeControls(scenario);
        }

        function setRunStatus(message, isError) {
            if (!$runResult.length) {
                return;
            }
            const cls = isError ? 'ucg-status-message ucg-status-message--error' : 'ucg-status-message ucg-status-message--ok';
            $runResult.html('<div class="' + cls + '">' + escapeHtml(message) + '</div>');
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

            const lines = logs.map(function (log) {
                const postId = Number(log && log.post_id ? log.post_id : 0);
                const statusLabel = log && log.status_label ? String(log.status_label) : jsT('Статус');
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
                    setRunStatus(msg, true);
                    clearRunMonitorTimer();
                    return;
                }

                const data = response.data || {};
                renderRunState(data);

                if (data.is_finished) {
                    setRunStatus(jsT('Готово. Запуск завершен.'), false);
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
                setRunStatus(jsT('AJAX ошибка при обновлении прогресса.'), true);
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

            $('.ucg-stepper__item').removeClass('is-active');
            $('.ucg-stepper__item[data-step-target="' + step + '"]').addClass('is-active');

            $('.ucg-step-panel').removeClass('is-active');
            $('.ucg-step-panel[data-step="' + step + '"]').addClass('is-active');

            if (step === 3) {
                renderRunSummary();
                setRunStatus('', false);
            }

            if (step === 2 && state.total === 0 && normalizeFilters().length === 0) {
                previewPosts(1, $('#ucg-preview-posts'));
            }
        }

        function updateSelectedCount() {
            $selectedCount.text(String(state.selectedIds.size));
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
            const emptyOptionLabel = (state.schema && String(state.schema.scenario || '') === 'seo_tags')
                ? jsT('Выберите SEO-плагин')
                : jsT('Выберите поле');
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
            if (hintText) {
                $varyLengthHint.text(hintText).show();
            } else {
                $varyLengthHint.text('').hide();
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

        function activeModelItem() {
            const selectedModelId = String($modelSelect.val() || state.defaultModel || 'auto');
            const models = Array.isArray(state.schema.generation_models) ? state.schema.generation_models : [];
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
            if (!$modelHint.length) {
                return;
            }

            const lengthOptionId = Number($lengthOption.val() || 0);
            const model = activeModelItem();
            const credits = estimateCreditsByLength(model, lengthOptionId);
            const modelName = model && model.name ? String(model.name) : jsT('По умолчанию');
            const provider = model && model.provider ? String(model.provider) : '';
            const resolved = model && model.resolved_model ? String(model.resolved_model) : '';
            let message = jsT('Оценка: ') + '~' + formatCreditsValue(credits, 2) + jsT(' кр/ед.');
            message += ' • ' + modelName;
            if (provider) {
                message += ' (' + provider + ')';
            }
            if (resolved) {
                message += ' • ' + resolved;
            }
            $modelHint.text(message);

            const unitLabel = state.schema && state.schema.generation_unit_label
                ? String(state.schema.generation_unit_label)
                : jsT('1 единица');
            if ($unitHint.length) {
                $unitHint.text(jsT('Единица расчёта: ') + unitLabel);
            }
            renderRunSummary();
        }

        function renderRunSummary() {
            if (!$runSummary.length) {
                return;
            }

            const scenario = getScenario();
            const planned = getPlannedCount();
            const lengthOptionId = Number($lengthOption.val() || 0);
            const model = activeModelItem();
            const creditsPerUnit = estimateCreditsByLength(model, lengthOptionId);
            const totalCredits = planned > 0 ? (planned * creditsPerUnit) : 0;
            const modelName = model && model.name ? String(model.name) : jsT('По умолчанию');
            const provider = model && model.provider ? String(model.provider) : '';
            const resolved = model && model.resolved_model ? String(model.resolved_model) : '';
            const scope = getSelectionMode() === 'filtered' ? jsT('Все найденные') : jsT('Выбранные вручную');
            const unitLabel = state.schema && state.schema.generation_unit_label
                ? String(state.schema.generation_unit_label)
                : jsT('1 единица');
            const perUnitLabel = scenario === 'seo_tags'
                ? jsT('Стоимость за SEO-пакет')
                : jsT('Стоимость за единицу');

            let modelBase = modelName || provider || jsT('По умолчанию');
            const providerSuffix = provider ? (' (' + provider + ')') : '';
            if (providerSuffix && modelBase.toLowerCase().endsWith(providerSuffix.toLowerCase())) {
                modelBase = modelBase.slice(0, -providerSuffix.length).trim();
            }

            let modelDetails = modelBase;
            if (resolved && modelDetails.toLowerCase().indexOf(resolved.toLowerCase()) === -1) {
                modelDetails += ' (' + resolved + ')';
            }

            let html = '' +
                '<div class="ucg-run-summary__head">' +
                '<h3 class="ucg-run-summary__title">' + escapeHtml(jsT('Сводка запуска')) + '</h3>' +
                '<strong class="ucg-run-summary__cost">~' + escapeHtml(formatCreditsValue(totalCredits, 2)) + ' ' + escapeHtml(jsT('кр.')) + '</strong>' +
                '</div>' +
                '<div class="ucg-run-summary__grid">' +
                '  <div class="ucg-run-summary__item"><span>' + escapeHtml(jsT('Записей')) + '</span><strong>' + escapeHtml(String(planned)) + '</strong></div>' +
                '  <div class="ucg-run-summary__item"><span>' + escapeHtml(perUnitLabel) + '</span><strong>~' + escapeHtml(formatCreditsValue(creditsPerUnit, 2)) + ' ' + escapeHtml(jsT('кр.')) + '</strong></div>' +
                '  <div class="ucg-run-summary__item"><span>' + escapeHtml(jsT('Модель')) + '</span><strong>' + escapeHtml(modelDetails) + '</strong></div>' +
                '  <div class="ucg-run-summary__item"><span>' + escapeHtml(jsT('Режим выборки')) + '</span><strong>' + escapeHtml(scope) + '</strong></div>' +
                '</div>' +
                '<p class="ucg-run-summary__hint">' + escapeHtml(jsT('Единица расчёта: ')) + escapeHtml(unitLabel) + '</p>';

            if (planned <= 0) {
                html += '<p class="ucg-run-summary__hint">' + escapeHtml(jsT('Выберите записи на шаге "Фильтрация".')) + '</p>';
            }

            $runSummary.html(html);
        }

        function renderGenerationModels() {
            const models = Array.isArray(state.schema.generation_models) ? state.schema.generation_models : [];
            const lengthOptionId = Number($lengthOption.val() || 0);
            const currentModel = getEnhancedSelectValue($modelSelect) || String(state.defaultModel || 'auto');
            let html = '';
            let selectedExists = false;
            let defaultModel = String(state.schema.default_model || state.defaultModel || 'auto');
            if (!defaultModel) {
                defaultModel = 'auto';
            }
            state.defaultModel = defaultModel;

            if (!models.length) {
                html = '<option value="auto">' + escapeHtml(jsT('По умолчанию')) + '</option>';
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
                    const credits = estimateCreditsByLength(item, lengthOptionId);
                    const label = name + ' — ~' + formatCreditsValue(credits, 2) + ' ' + jsT('кр/ед.');
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

            if (isSelected) {
                $templateNameWrap.hide();
                $saveTemplateLabel.text(jsT('Сохранить изменения в шаблоне'));
                return;
            }

            $templateNameWrap.show();
            $saveTemplateLabel.text(jsT('Сохранить шаблон'));
        }

        function getPlannedCount() {
            if (getSelectionMode() === 'filtered') {
                return Number(state.total || 0);
            }
            return Number(state.selectedIds.size || 0);
        }

        function getSelectionMode() {
            return String($selectionMode.filter(':checked').val() || 'selected');
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
            if (mode === 'filtered') {
                $previewSummary.text(jsT('Будут использованы все найденные записи: ') + state.total + '.');
            } else {
                $previewSummary.text(jsT('Выбрано вручную: ') + state.selectedIds.size + jsT('. Найдено по фильтру: ') + state.total + '.');
            }
            renderRunSummary();
        }

        function previewPosts(page, $button) {
            const postType = String($postType.val() || '');
            const scenario = getScenario();
            if (!postType) {
                setRunStatus(jsT('Выберите тип записей.'), true);
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

        function loadTemplate(templateId) {
            const id = Number(templateId || 0);
            updateTemplateMode();
            if (!id) {
                $templateBody.val('');
                $templateBodySeoTitle.val('');
                $templateBodySeoDescription.val('');
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
            }).fail(function () {
                setRunStatus(jsT('AJAX ошибка при загрузке шаблона.'), true);
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
                renderTemplates();
                renderWizardTokens();
                clearFilters();
                state.selectedIds.clear();
                updateSelectedCount();
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

        function startRun($button) {
            const scenario = getScenario();
            const postType = String($postType.val() || '');
            const targetField = String($targetField.val() || '');
            const templateId = Number($templateSelect.val() || 0);
            const templateName = String($templateName.val() || '').trim();
            const templateBody = String($templateBody.val() || '').trim();
            const templateBodySeoTitle = String($templateBodySeoTitle.val() || '').trim();
            const templateBodySeoDescription = String($templateBodySeoDescription.val() || '').trim();
            const mode = getSelectionMode();
            const filters = normalizeFilters();
            const lengthOptionId = Number($lengthOption.val() || 0);
            const model = String($modelSelect.val() || state.defaultModel || 'auto');
            const varyLength = $varyLength.is(':checked') ? 1 : 0;
            const publishDateRange = collectPublishDateRangeForRun(scenario);

            if (!postType) {
                setRunStatus(jsT('Выберите тип записей.'), true);
                switchStep(1);
                return;
            }

            if (!targetField) {
                setRunStatus(jsT('Выберите целевое поле.'), true);
                switchStep(1);
                return;
            }

            if (!publishDateRange.valid) {
                setRunStatus(publishDateRange.message || jsT('Проверьте диапазон дат публикации.'), true);
                return;
            }

            if (scenario === 'seo_tags') {
                if (!templateBodySeoTitle || !templateBodySeoDescription) {
                    setRunStatus(jsT('Заполните шаблоны для SEO title и SEO description.'), true);
                    return;
                }
            } else if (!templateBody) {
                setRunStatus(jsT('Шаблон пустой. Заполните текст.'), true);
                return;
            }

            if (lengthOptionId <= 0) {
                setRunStatus(jsT('Выберите диапазон длины текста.'), true);
                return;
            }

            if (templateId <= 0 && $saveTemplateChanges.is(':checked') && !templateName) {
                setRunStatus(jsT('Введите название шаблона, чтобы сохранить его.'), true);
                return;
            }

            if (mode === 'selected' && state.selectedIds.size === 0) {
                setRunStatus(jsT('Выберите записи вручную или переключитесь на режим "все найденные".'), true);
                switchStep(2);
                return;
            }

            const startingText = (window.ucgAdmin && window.ucgAdmin.strings && ucgAdmin.strings.starting_run) ? ucgAdmin.strings.starting_run : jsT('Создаем запуск...');
            setRunStatus(startingText, false);
            setButtonLoading($button, true);

            $.post(ucgAdmin.ajaxUrl, {
                action: 'ucg_wizard_create_run',
                nonce: ucgAdmin.nonce,
                scenario: scenario,
                post_type: postType,
                target_field: targetField,
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
                selected_ids: JSON.stringify(Array.from(state.selectedIds)),
                filters: JSON.stringify(filters)
            }).done(function (response) {
                if (!response.success) {
                    const msg = response.data && response.data.message ? response.data.message : jsT('Не удалось создать запуск.');
                    setRunStatus(msg, true);
                    return;
                }

                const data = response.data || {};
                const runId = Number(data.run_id || 0);
                const queued = Number(data.queued || 0);
                const progressUrl = String(data.progress_url || (runId > 0 ? ('admin.php?page=ucg-run-progress&run_id=' + runId) : ''));

                if (progressUrl) {
                    setRunStatus(jsT('Запуск #') + runId + jsT(' создан. В очереди: ') + queued + jsT('. Открываем страницу прогресса...'), false);
                    window.setTimeout(function () {
                        window.location.href = progressUrl;
                    }, 250);
                    return;
                }

                setRunStatus(jsT('Запуск #') + runId + jsT(' создан. В очереди: ') + queued + '.', false);
            }).fail(function () {
                setRunStatus(jsT('AJAX ошибка при создании запуска.'), true);
            }).always(function () {
                setButtonLoading($button, false);
            });
        }

        function bindEvents() {
            $('#ucg-step-1-next').on('click', function () {
                const scenario = getScenario();
                const postType = String($postType.val() || '');
                const targetField = String($targetField.val() || '');
                if (!scenario) {
                    setRunStatus(jsT('Выберите сценарий генерации.'), true);
                    return;
                }
                if (!postType) {
                    setRunStatus(jsT('Выберите тип записей.'), true);
                    return;
                }
                if (!targetField) {
                    setRunStatus(jsT('Выберите целевое поле.'), true);
                    return;
                }
                switchStep(2);
                if (state.total === 0) {
                    previewPosts(1, $('#ucg-preview-posts'));
                }
            });

            $('#ucg-step-2-back').on('click', function () {
                switchStep(1);
            });

            $('#ucg-step-2-next').on('click', function () {
                const planned = getPlannedCount();
                if (planned <= 0) {
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
                const postType = String($(this).val() || '');
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
                getScenario();
                const postType = String($postType.val() || '');
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

            $wizardTokenSearch.on('input', function () {
                renderWizardTokens();
            });

            $templateSelect.on('change', function () {
                loadTemplate($(this).val());
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

            $('#ucg-start-run').on('click', function () {
                startRun($(this));
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
        clearFilters();
        updateSelectedCount();
        updatePagination();
        updateSummary();
        bindEvents();
        const hasSchemaTargetFields = Array.isArray(state.schema.target_fields) && state.schema.target_fields.length > 0;
        const hasSchemaLengthOptions = Array.isArray(state.schema.text_length_options) && state.schema.text_length_options.length > 0;
        if (!hasSchemaTargetFields || !hasSchemaLengthOptions) {
            refreshSchema(String($postType.val() || ''), function () {
                previewPosts(1, $('#ucg-preview-posts'));
            }, null, true);
            return;
        }
        previewPosts(1, $('#ucg-preview-posts'));
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
        let timer = null;

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
            const cls = isError ? 'ucg-status-message ucg-status-message--error' : 'ucg-status-message ucg-status-message--ok';
            $status.html('<div class="' + cls + '">' + escapeHtml(message) + '</div>');
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

            const lines = logs.map(function (entry) {
                const postId = Number(entry && entry.post_id ? entry.post_id : 0);
                const statusLabel = entry && entry.status_label ? String(entry.status_label) : jsT('Статус');
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

            if ($title.length) {
                $title.text(jsT('Запуск #') + currentRunId);
            }
            setChipStatus(run.status, run.status_label);
            $progressBar.css('width', progress + '%');
            $stats.text(progress + jsT('% • обработано ') + processed + jsT(' из ') + total + jsT(' • в очереди ') + queued + jsT(' • ошибок ') + failed + jsT(' • готово ') + success);
            renderLog(Array.isArray(data.logs) ? data.logs : []);
        }

        function poll() {
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

                const run = data.run || {};
                const runStatus = String(run.status || '');
                const processed = Number(run.processed_items || 0);
                const nextPollDelay = (runStatus === 'queued' || processed <= 0) ? 1000 : 3000;

                setStatus(jsT('Генерация в процессе. Страница обновляется автоматически.'), false);
                clearTimer();
                timer = window.setTimeout(poll, nextPollDelay);
            }).fail(function () {
                setStatus(jsT('AJAX ошибка при обновлении прогресса.'), true);
                clearTimer();
                timer = window.setTimeout(poll, 5000);
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
    initGenerateWizard();
    initRunProgressPage();

    const currentKeyText = String($('#ucg-current-key').text() || '').trim();
    setApiKeyUiState(currentKeyText !== '' && currentKeyText !== jsT('не задан'), currentKeyText);

    if ($('.ucg-balance-value').length) {
        fetchBalance(false, $('#ucg-refresh-balance'));
    }
});
