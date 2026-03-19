<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UCG_I18n')) {
    class UCG_I18n {
        const DOMAIN = 'unicontent-ai-generator';

        public static function hooks() {
            add_filter('gettext', array(__CLASS__, 'filter_gettext'), 20, 3);
        }

        public static function filter_gettext($translated, $text, $domain) {
            if ((string) $domain !== self::DOMAIN || !self::is_english_locale()) {
                return $translated;
            }

            $map = self::get_translations();
            if (isset($map[$text])) {
                return (string) $map[$text];
            }

            return $translated;
        }

        public static function translate_markup($html) {
            $html = (string) $html;
            if ($html === '' || !self::is_english_locale()) {
                return $html;
            }

            $map = self::get_translations();
            if (empty($map)) {
                return $html;
            }

            // Replace longer phrases first to avoid partial replacements.
            uksort(
                $map,
                function ($a, $b) {
                    $a_len = function_exists('mb_strlen') ? mb_strlen((string) $a, 'UTF-8') : strlen((string) $a);
                    $b_len = function_exists('mb_strlen') ? mb_strlen((string) $b, 'UTF-8') : strlen((string) $b);
                    return $b_len <=> $a_len;
                }
            );

            return strtr($html, $map);
        }

        public static function get_js_i18n_map() {
            if (!self::is_english_locale()) {
                return array();
            }
            return self::get_translations();
        }

        protected static function is_english_locale() {
            $locale = '';
            if (is_admin() && function_exists('get_user_locale')) {
                $locale = (string) get_user_locale();
            }
            if ($locale === '' && function_exists('determine_locale')) {
                $locale = (string) determine_locale();
            }
            if ($locale === '' && function_exists('get_locale')) {
                $locale = (string) get_locale();
            }

            $locale = strtolower(trim($locale));
            return $locale === 'en' || strpos($locale, 'en_') === 0;
        }

        protected static function get_translations() {
            static $map = null;
            if (is_array($map)) {
                return $map;
            }

            $map = array(
                'AI-Контент' => 'AI Content',
                'Дашборд' => 'Dashboard',
                'Шаблоны' => 'Templates',
                'Генерация' => 'Generation',
                'Проверка' => 'Review',
                'История' => 'History',
                'Настройки' => 'Settings',
                'Готовые шаблоны' => 'Ready Templates',
                'Прогресс запуска' => 'Run Progress',

                'UNICONTENT — AI генератор контента' => 'UNICONTENT — AI Content Generator',
                'Сначала добавьте API ключ из личного кабинета, затем запускайте генерацию в пару шагов.' => 'First add an API key from your dashboard, then start generation in a few steps.',
                'Новый запуск' => 'New run',
                'API ключ' => 'API key',
                'Скопируйте ключ в личном кабинете и вставьте его в поле ниже.' => 'Copy the key from your account dashboard and paste it below.',
                'Создать ключ' => 'Create key',
                'Ключ API' => 'API key',
                'Ключ сохранён' => 'Key saved',
                'Вставьте API ключ' => 'Paste API key',
                'скрыт' => 'hidden',
                'Удалить ключ' => 'Delete key',
                'Текущий:' => 'Current:',
                'не задан' => 'not set',
                'Проверен' => 'Verified',
                'Не проверен' => 'Not verified',
                'Кредиты:' => 'Credits:',
                'Обновить' => 'Refresh',
                'Сохранить ключ' => 'Save key',
                'Сохранить' => 'Save',
                'Пополнить' => 'Top up',
                'Очередь' => 'Queue',
                'В очереди' => 'Queued',
                'В работе' => 'Running',
                'Готово' => 'Done',
                'Ошибки' => 'Errors',
                'Последние запуски' => 'Recent runs',
                'Запусков пока нет.' => 'No runs yet.',
                'Статус' => 'Status',
                'Поле' => 'Field',
                'Поле: ' => 'Field: ',
                'Прогресс' => 'Progress',
                'Создан' => 'Created',

                'Каталог готовых шаблонов' => 'Ready Template Catalog',
                'Запрос к библиотеке UNICONTENT выполняется только на этой странице. Выберите шаблон и установите его в нужный post type.' => 'Requests to the UNICONTENT library are performed only on this page. Choose a template and install it for the required post type.',
                'Назад к шаблонам' => 'Back to templates',
                'Фильтруйте по типу и устанавливайте нужные карточки в один клик.' => 'Filter by type and install suitable cards in one click.',
                'Тип' => 'Type',
                'Все типы' => 'All types',
                'Не удалось загрузить готовые шаблоны: %s' => 'Failed to load ready templates: %s',
                'Подходящих готовых шаблонов не найдено.' => 'No suitable ready templates found.',
                'Установлено' => 'Installed',
                'Шаблон #%1$d, post type: <code>%2$s</code>' => 'Template #%1$d, post type: <code>%2$s</code>',
                'Переустановить' => 'Reinstall',
                'Установить' => 'Install',
                'Удалить установленный шаблон?' => 'Delete installed template?',
                'Удалить' => 'Delete',
                'По выбранному типу шаблонов не найдено.' => 'No templates found for the selected type.',

                'Генерация контента' => 'Content generation',
                'Нужен API ключ' => 'API key required',
                'Перед использованием мастера добавьте и проверьте API ключ на дашборде.' => 'Before using the wizard, add and verify an API key on the dashboard.',
                'Перейти на дашборд' => 'Go to dashboard',
                'Тип и поле' => 'Type and field',
                'Фильтрация' => 'Filtering',
                'Шаблон и запуск' => 'Template and run',
                'Тип записей' => 'Post type',
                'Выберите тип' => 'Select type',
                'Целевое поле' => 'Target field',
                'Выберите поле' => 'Select field',
                'Далее' => 'Next',
                'Фильтры' => 'Filters',
                'Необязательно. Без фильтров показываем все записи выбранного типа.' => 'Optional. Without filters, all posts of the selected type are shown.',
                '+ Добавить фильтр' => '+ Add filter',
                'Обновить список' => 'Refresh list',
                'Загружаем записи выбранного типа...' => 'Loading records for the selected type...',
                'Выбрать нужные записи вручную' => 'Select required records manually',
                'Генерировать для всех найденных записей' => 'Generate for all found records',
                'Загружаем записи...' => 'Loading records...',
                'Заголовок' => 'Title',
                'Дата' => 'Date',
                'Выбрано вручную:' => 'Manually selected:',
                'Назад' => 'Back',
                'Шаблон' => 'Template',
                'Не выбрано' => 'Not selected',
                'Название шаблона' => 'Template name',
                'Например: SEO описание товара' => 'For example: Product SEO description',
                'Сохранить изменения в шаблоне' => 'Save changes to template',
                'Сохранить шаблон' => 'Save template',
                'Длина текста' => 'Text length',
                '— до ' => ' — up to ',
                ' символов / ' => ' characters / ',
                ' кр.' => ' cr.',
                'Нет доступных диапазонов' => 'No ranges available',
                'Варьировать длину текста' => 'Vary text length',
                'Текст шаблона' => 'Template text',
                'Переменные' => 'Variables',
                'Кликните или перетащите переменную в текст' => 'Click or drag a variable into the text',
                'Запустить генерацию' => 'Start generation',

                'Шаблоны промптов' => 'Prompt templates',
                'Собирайте промпт из токенов: название, контент, метаполя, ACF и WooCommerce.' => 'Build a prompt from tokens: title, content, meta fields, ACF and WooCommerce.',
                'Редактировать шаблон' => 'Edit template',
                'Новый шаблон' => 'New template',
                'Название' => 'Name',
                'Post type' => 'Post type',
                'Варьировать длину текста' => 'Vary text length',
                'Сделать шаблоном по умолчанию для выбранного post type' => 'Set as default template for selected post type',
                'Отменить редактирование' => 'Cancel editing',
                'Токены' => 'Tokens',
                'Клик или drag-and-drop в поле шаблона' => 'Click or drag-and-drop into the template field',
                'Список шаблонов' => 'Template list',
                'Шаблонов пока нет.' => 'No templates yet.',
                'По умолчанию' => 'Default',
                'Действия' => 'Actions',
                'Да' => 'Yes',
                'Редактировать' => 'Edit',
                'Удалить шаблон?' => 'Delete template?',

                'Проверка результатов' => 'Review results',
                'Сначала смотрите сгенерированный текст, затем массово одобряйте или отклоняйте.' => 'First review generated text, then approve or reject in bulk.',
                'Запуск' => 'Run',
                'Вся история' => 'All history',
                'Сгенерировано' => 'Generated',
                'Одобрено' => 'Approved',
                'Отклонено' => 'Rejected',
                'Ошибка' => 'Error',
                'Показать' => 'Show',
                'Элементы' => 'Items',
                'Всего:' => 'Total:',
                'По текущему фильтру элементов нет.' => 'No items for current filter.',
                'Запись' => 'Post',
                'Текущее значение' => 'Current value',
                'Сгенерированный текст' => 'Generated text',
                'Просмотр' => 'Preview',
                'Выберите действие' => 'Choose action',
                'Одобрить' => 'Approve',
                'Отклонить' => 'Reject',
                'Применить' => 'Apply',
                'Просмотр результата' => 'Result preview',
                'Закрыть' => 'Close',

                'Процесс генерации' => 'Generation process',
                'Запуск не найден или не указан.' => 'Run not found or not specified.',
                'К генерации' => 'To generation',
                'К истории' => 'To history',
                'Запуск #' => 'Run #',
                '% • обработано ' => '% • processed ',
                ' из ' => ' of ',
                ' • в очереди ' => ' • queued ',
                ' • ошибок ' => ' • errors ',
                ' • готово ' => ' • done ',
                'Запуск завершён. Можно перейти к проверке.' => 'Run is complete. You can proceed to review.',
                'Генерация в процессе. Страница обновляется автоматически.' => 'Generation in progress. The page refreshes automatically.',
                'Логи появятся после обработки первых записей.' => 'Logs will appear after the first records are processed.',
                'Проверить результаты' => 'Review results',

                'История запусков и текущий статус обработки очереди.' => 'Run history and current queue processing status.',
                'Целевое поле' => 'Target field',
                'Завершен' => 'Completed',
                'Прогресс' => 'Progress',

                'Минимальные настройки для запуска генерации: API ключ и размер шага обработки.' => 'Minimum settings to start generation: API key and processing batch size.',
                'Вставьте ключ из личного кабинета UNICONTENT. Без ключа генерация не начнётся.' => 'Paste the key from your UNICONTENT account. Generation cannot start without a key.',
                'Скорость обработки' => 'Processing speed',
                'Параметр определяет, сколько записей обрабатывается за один шаг очереди.' => 'This setting controls how many records are processed in one queue step.',
                'Меньше значение: стабильнее на слабом хостинге.' => 'Lower value: more stable on weak hosting.',
                'Больше значение: быстрее общий прогон, но выше нагрузка.' => 'Higher value: faster overall run but higher load.',
                'Рекомендуем начать с ' => 'We recommend starting with ',
                'и увеличивать постепенно.' => 'and increasing gradually.',
                'Записей за шаг (1-100)' => 'Records per step (1-100)',
                'После генерации' => 'After generation',
                'Сначала проверка (по умолчанию)' => 'Review first (default)',
                'Публиковать сразу без проверки' => 'Publish immediately without review',

                'Пустой промпт.' => 'Empty prompt.',
                'Сначала добавьте API ключ на дашборде плагина.' => 'First add an API key in the plugin dashboard.',
                'Ошибка ответа API.' => 'API response error.',
                'Некорректный элемент очереди.' => 'Invalid queue item.',
                'Шаблон не найден или пустой.' => 'Template not found or empty.',
                'Шаблон не найден.' => 'Template not found.',
                'Промпт пустой после подстановки переменных.' => 'Prompt is empty after token substitution.',
                'Промпт пустой.' => 'Prompt is empty.',
                'API вернул пустой результат.' => 'API returned an empty result.',
                'Пустой ответ от API.' => 'Empty response from API.',
                'Every minute (UNICONTENT)' => 'Every minute (UNICONTENT)',

                'Некорректный post type.' => 'Invalid post type.',
                'Введите API ключ.' => 'Enter API key.',
                'Ключ сохранен, но проверка не пройдена: %s' => 'Key saved, but verification failed: %s',
                'Ключ сохранен и проверен.' => 'Key saved and verified.',
                'Ключ удален.' => 'Key removed.',
                'Публиковать сразу' => 'Publish immediately',
                'Сначала проверка' => 'Review first',
                'Сохранено. За шаг: до %d записей. Режим: %s.' => 'Saved. Per step: up to %d records. Mode: %s.',
                'Некорректный ID запуска.' => 'Invalid run ID.',
                'Запуск не найден.' => 'Run not found.',
                'Сначала добавьте и проверьте API ключ.' => 'First add and verify API key.',
                'Некорректный ID шаблона.' => 'Invalid template ID.',
                'Выберите корректное целевое поле.' => 'Select a valid target field.',
                'Текст шаблона не может быть пустым.' => 'Template text cannot be empty.',
                'Введите название шаблона.' => 'Enter template name.',
                'Не удалось сохранить новый шаблон.' => 'Failed to save new template.',
                'Выберите диапазон длины текста.' => 'Select text length range.',
                'Не выбраны записи для генерации.' => 'No records selected for generation.',
                'Не удалось создать запуск.' => 'Failed to create run.',
                'Не удалось добавить записи в очередь.' => 'Failed to add records to queue.',
                'Запуск #%d создан. В очереди: %d.' => 'Run #%d created. Queued: %d.',
                'Заполните название, post type и текст шаблона.' => 'Fill in template name, post type and template text.',
                'Не удалось обновить шаблон.' => 'Failed to update template.',
                'Шаблон обновлен.' => 'Template updated.',
                'Не удалось создать шаблон.' => 'Failed to create template.',
                'Шаблон создан.' => 'Template created.',
                'Не удалось удалить шаблон.' => 'Failed to delete template.',
                'Шаблон удален.' => 'Template deleted.',
                'Некорректный ID готового шаблона.' => 'Invalid ready template ID.',
                'Готовый шаблон не найден в библиотеке UNICONTENT.' => 'Ready template not found in UNICONTENT library.',
                'Не удалось установить шаблон: пустое имя или текст.' => 'Failed to install template: empty name or body.',
                'Выберите диапазон длины текста перед установкой.' => 'Select text length range before installation.',
                'Не удалось установить готовый шаблон.' => 'Failed to install ready template.',
                'Готовый шаблон установлен.' => 'Ready template installed.',
                'Готовый шаблон удален.' => 'Ready template deleted.',
                'Выберите действие: одобрить или отклонить.' => 'Select an action: approve or reject.',
                'Выберите хотя бы один элемент.' => 'Select at least one item.',
                'Выполнено: %d. Ошибок: %d.' => 'Done: %d. Errors: %d.',
                'Очередь обработана одним шагом.' => 'Queue processed in one step.',
                'Подключение к API работает.' => 'API connection is working.',
                'Текст будет генерироваться с небольшим разбросом длины внутри выбранного диапазона, чтобы результаты выглядели естественнее.' => 'Text will be generated with slight length variation within the selected range to make results look more natural.',
                'Доступ запрещен.' => 'Access denied.',
                '…' => '...',

                'Баланс:' => 'Balance:',
                'Баланс: ' => 'Balance: ',
                'Ошибка загрузки баланса.' => 'Failed to load balance.',
                'Не удалось сохранить API ключ.' => 'Failed to save API key.',
                'Удаляем API ключ...' => 'Deleting API key...',
                'Не удалось удалить API ключ.' => 'Failed to delete API key.',
                'AJAX ошибка при сохранении ключа.' => 'AJAX error while saving key.',
                'AJAX ошибка при удалении ключа.' => 'AJAX error while deleting key.',
                'AJAX ошибка при получении баланса.' => 'AJAX error while fetching balance.',
                'AJAX ошибка при сохранении настроек.' => 'AJAX error while saving settings.',
                'Не удалось сохранить размер шага.' => 'Failed to save batch size.',
                'Сохранено: ' => 'Saved: ',
                'Ожидаем первые события...' => 'Waiting for first events...',
                'События появятся после первых обработанных записей.' => 'Events will appear after first processed records.',
                'Готово. Запуск завершен.' => 'Done. Run completed.',
                'AJAX ошибка при обновлении прогресса.' => 'AJAX error while updating progress.',
                'К запуску найдено записей: ' => 'Records found for run: ',
                'К запуску выбрано записей: ' => 'Records selected for run: ',
                'Запуск создан. Ждём первые ответы от API...' => 'Run created. Waiting for first API responses...',
                'Не удалось получить прогресс запуска.' => 'Failed to get run progress.',
                'Не удалось загрузить записи.' => 'Failed to load records.',
                'Найдено записей: ' => 'Records found: ',
                'AJAX ошибка при фильтрации записей.' => 'AJAX error while filtering records.',
                'Не удалось загрузить шаблон.' => 'Failed to load template.',
                'AJAX ошибка при загрузке шаблона.' => 'AJAX error while loading template.',
                'Не удалось загрузить схему.' => 'Failed to load schema.',
                'AJAX ошибка при загрузке схемы.' => 'AJAX error while loading schema.',
                'Шаблон пустой. Заполните текст.' => 'Template is empty. Fill in the text.',
                'Введите название шаблона, чтобы сохранить его.' => 'Enter a template name to save it.',
                'Выберите записи вручную или переключитесь на режим "все найденные".' => 'Select records manually or switch to "all found" mode.',
                'Не удалось создать запуск.' => 'Failed to create run.',
                'Открываем страницу прогресса...' => 'Opening progress page...',
                'Сначала загрузите записи.' => 'Load records first.',
                'Ничего не найдено по фильтрам.' => 'Nothing found by filters.',
                'Переменные не найдены.' => 'No variables found.',
                '  <select class="ucg-filter-field ucg-enhanced-select" data-search-enabled="false" data-placeholder="Поле">' => '  <select class="ucg-filter-field ucg-enhanced-select" data-search-enabled="false" data-placeholder="Field">',
                '" placeholder="значение">' => '" placeholder="value">',
                '  <button type="button" class="button button-small ucg-remove-filter-row">Удалить</button>' => '  <button type="button" class="button button-small ucg-remove-filter-row">Delete</button>',
                'пусто' => 'empty',
                'не пусто' => 'not empty',
                'содержит' => 'contains',
                'не содержит' => 'does not contain',
                'равно' => 'equals',
                'не равно' => 'does not equal',
                'Содержимое (post_content)' => 'Content (post_content)',
                'Ничего не найдено' => 'No results found',
                'Ничего не найдено для: ' => 'No results for: ',
                ' · ' => ' · ',
                'создан. В очереди: ' => 'created. Queued: ',
                ' создан. В очереди: ' => ' created. Queued: ',
                ' — ' => ' — ',
                ' — до ' => ' — up to ',
                '. Найдено по фильтру: ' => '. Found by filter: ',
                '. Открываем страницу прогресса...' => '. Opening progress page...',
                '0% • в очереди ' => '0% • queued ',
                '<div class="no-results">Ничего не найдено для: <strong>' => '<div class="no-results">No results for: <strong>',
                '<div class="no-results">Ничего не найдено</div>' => '<div class="no-results">No results found</div>',
                '<div class="ucg-muted">Запуск создан. Ждём первые ответы от API...</div>' => '<div class="ucg-muted">Run created. Waiting for first API responses...</div>',
                '<div class="ucg-muted">Логи появятся после обработки первых записей.</div>' => '<div class="ucg-muted">Logs will appear after first records are processed.</div>',
                '<div class="ucg-muted">Ожидаем первые события...</div>' => '<div class="ucg-muted">Waiting for first events...</div>',
                '<div class="ucg-muted">События появятся после первых обработанных записей.</div>' => '<div class="ucg-muted">Events will appear after first processed records.</div>',
                '<option value="">Выберите поле</option>' => '<option value="">Select field</option>',
                '<option value="">Выберите промпт</option>' => '<option value="">Select prompt</option>',
                '<option value="">Не выбрано</option>' => '<option value="">Not selected</option>',
                '<option value="">Нет доступных диапазонов</option>' => '<option value="">No ranges available</option>',
                '<p class="ucg-muted">Переменные не найдены.</p>' => '<p class="ucg-muted">No variables found.</p>',
                '<span class="ucg-page-dots">…</span>' => '<span class="ucg-page-dots">...</span>',
                '<tr><td colspan="5">Загружаем записи...</td></tr>' => '<tr><td colspan="5">Loading records...</td></tr>',
                '<tr><td colspan="5">Ничего не найдено по фильтрам.</td></tr>' => '<tr><td colspan="5">Nothing found by filters.</td></tr>',
                'AJAX ошибка при создании запуска.' => 'AJAX error while creating run.',
                '\\s+—\\s*' => '\\s+—\\s*',
                'Большое' => 'Large',
                'Будут использованы все найденные записи: ' => 'All found records will be used: ',
                'Выберите тип записей.' => 'Select post type.',
                'Выберите целевое поле.' => 'Select target field.',
                'Выбрано вручную: ' => 'Manually selected: ',
                'Заголовок (post_title)' => 'Title (post_title)',
                'Запись #' => 'Post #',
                'Запуск завершён. Можно открыть проверку результатов.' => 'Run completed. You can open result review.',
                'Короткое' => 'Short',
                'Краткое описание (post_excerpt)' => 'Excerpt (post_excerpt)',
                'Не удалось обновить прогресс.' => 'Failed to update progress.',
                'Обновляем баланс...' => 'Refreshing balance...',
                'Расширенное' => 'Extended',
                'Содержание (post_content)' => 'Content (post_content)',
                'Создаем запуск...' => 'Creating run...',
                'Сохраняем API ключ...' => 'Saving API key...',
                'Сохраняем настройки...' => 'Saving settings...',
                'Стандартное' => 'Standard',
                '—' => '—',
            );

            return $map;
        }
    }
}
