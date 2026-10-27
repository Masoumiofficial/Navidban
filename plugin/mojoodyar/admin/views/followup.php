<?php
/**
 * Standalone phone follow-up screen.
 *
 * This page exists for one reason: a browser without JavaScript cannot open the
 * <dialog> on the waitlist screen. The "پیگیری تلفنی" link points here, and the
 * admin script turns that click into a dialog when JavaScript is available. The
 * form is the same partial, so the two paths cannot drift apart.
 *
 * The page is registered as a submenu and immediately removed from the menu, so
 * it is reachable by URL but never adds a menu item.
 *
 * @var array $row     Waitlist row.
 * @var array $history CallLog history for the row.
 *
 * @package Mojoodyar
 */

defined( 'ABSPATH' ) || exit;

use Mojoodyar\Support\Jalali;
use Mojoodyar\Support\Persian;

$return  = '';
$variant = 'page';
?>
<div class="wrap mjy-wrap" dir="rtl">
	<header class="mjy-hero">
		<span class="mjy-hero__mark" aria-hidden="true">
			<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4.5 5.5c0 7 7 14 14 14l2-3.5-4-2-2 2a12 12 0 0 1-6.5-6.5l2-2-2-4-3.5 2Z" /></svg>
		</span>
		<div class="mjy-hero__text">
			<h1 class="mjy-hero__title">پیگیری تلفنی<small>تماس، نتیجه و پایان پیگیری</small></h1>
		</div>
		<div class="mjy-hero__meta">
			<span class="mjy-chip">ردیف <?php echo esc_html( Persian::number( (int) $row['id'] ) ); ?></span>
		</div>
	</header>

	<div class="mjy-panel">
		<p class="mjy-muted">
			ثبت‌شده در <?php echo esc_html( Jalali::format( (string) $row['created_at'], 'j F Y — H:i' ) ); ?>
		</p>

		<?php include MOJODYAR_DIR . 'admin/views/call-panel.php'; ?>

		<p class="mjy-follow__back">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mojoodyar-waitlists' ) ); ?>">بازگشت به لیست انتظارها</a>
		</p>
	</div>
</div>
