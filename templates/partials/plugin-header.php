<?php
$header_balance_text = '—';
$header_has_balance = false;
if (isset($header_balance) && $header_balance !== null && $header_balance !== '') {
    $header_balance_value = (float) $header_balance;
    $header_balance_text = number_format($header_balance_value, 2, '.', '');
    $header_balance_text = rtrim(rtrim($header_balance_text, '0'), '.');
    if ($header_balance_text === '') {
        $header_balance_text = '0';
    }
    $header_has_balance = true;
}
$header_title_text = isset($header_title) && $header_title !== '' ? (string) $header_title : 'UNICONTENT';
$header_hide_mark_flag = !empty($header_hide_mark);
?>
<header class="ucg-plugin-header">
    <div class="ucg-plugin-header__brand">
        <?php if (!$header_hide_mark_flag) : ?>
            <span class="ucg-plugin-header__mark" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                    <path d="M2 17l10 5 10-5"/>
                    <path d="M2 12l10 5 10-5"/>
                </svg>
            </span>
        <?php endif; ?>
        <span class="ucg-plugin-header__name"><?php echo esc_html($header_title_text); ?></span>
    </div>

    <div class="ucg-plugin-header__actions">
        <div class="ucg-plugin-header__balance">
            <span>Баланс:</span>
            <strong class="ucg-balance-value"><?php echo esc_html($header_balance_text); ?></strong>
            <?php if ($header_has_balance) : ?>
                <span class="ucg-plugin-header__currency">кр.</span>
            <?php endif; ?>
        </div>
        <a href="https://unicontent.net/dashboard/billing" target="_blank" rel="noopener noreferrer" class="button ucg-btn ucg-btn--secondary">Пополнить</a>
    </div>
</header>
