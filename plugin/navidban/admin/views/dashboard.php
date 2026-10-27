<?php
/**
 * Dashboard view.
 *
 * @var array $counts     Waitlist counters.
 * @var array $queue      Queue counters.
 * @var int   $last_run   Last reconcile timestamp.
 * @var array $tables     Table engines.
 * @var array $conversion Conversion stats.
 *
 * @package Navidban
 */

defined( 'ABSPATH' ) || exit;

use Navidban\Support\Jalali;
use Navidban\Support\Persian;

$nvb_healthy = ! in_array( 'missing', $tables, true ) && ! in_array( 'MyISAM', $tables, true );
?>
<?php $this->open_screen( 'dashboard', 'نویدبان — داشبورد', 'نگهبانِ خبرهای خوب فروشگاه شما' ); ?>

	<?php if ( ! $nvb_healthy ) : ?>
		<div class="notice notice-error inline">
			<p><strong>ساختار پایگاه داده کامل نیست.</strong> صفحهٔ «ابزارها» را باز کنید و «بازسازی جدول‌ها» را بزنید.</p>
		</div>
	<?php endif; ?>

	<div class="nvb-grid">
		<div class="nvb-card">
			<span class="nvb-card__label">در انتظار تأیید</span>
			<strong class="nvb-card__value"><?php echo esc_html( Persian::number( $counts['pending'] ) ); ?></strong>
		</div>
		<div class="nvb-card">
			<span class="nvb-card__label">تأییدشده و در صف</span>
			<strong class="nvb-card__value"><?php echo esc_html( Persian::number( $counts['confirmed'] ) ); ?></strong>
		</div>
		<div class="nvb-card">
			<span class="nvb-card__label">اعلان‌شده</span>
			<strong class="nvb-card__value"><?php echo esc_html( Persian::number( $counts['notified'] ) ); ?></strong>
		</div>
		<div class="nvb-card">
			<span class="nvb-card__label">تماس گرفته‌شده</span>
			<strong class="nvb-card__value"><?php echo esc_html( Persian::number( $counts['called'] ) ); ?></strong>
		</div>
		<div class="nvb-card">
			<span class="nvb-card__label">لغو اشتراک</span>
			<strong class="nvb-card__value"><?php echo esc_html( Persian::number( $counts['unsubscribed'] ) ); ?></strong>
		</div>
	</div>

	<div class="nvb-panel">
		<h2>وضعیت صف ارسال</h2>
		<table class="widefat striped nvb-table">
			<tbody>
				<tr><th>در صف</th><td><?php echo esc_html( Persian::number( $queue['queued'] ) ); ?></td></tr>
				<tr><th>در حال ارسال</th><td><?php echo esc_html( Persian::number( $queue['processing'] ) ); ?></td></tr>
				<tr><th>ارسال‌شده</th><td><?php echo esc_html( Persian::number( $queue['sent'] ) ); ?></td></tr>
				<tr><th>ناموفق (پس از ۳ تلاش)</th><td><?php echo esc_html( Persian::number( $queue['failed'] ) ); ?></td></tr>
				<tr><th>ردشده (لغو/تأییدنشده)</th><td><?php echo esc_html( Persian::number( $queue['skipped'] ) ); ?></td></tr>
				<tr>
					<th>قدیمی‌ترین پیام در انتظار</th>
					<td><?php echo $queue['oldest'] ? esc_html( Jalali::format( (string) $queue['oldest'], 'datetime' ) ) : '—'; ?></td>
				</tr>
			</tbody>
		</table>

	</div>
	<div class="nvb-panel">
		<h2>پایش تطبیقی</h2>
		<p>
			<?php if ( $last_run > 0 ) : ?>
				آخرین اجرا: <strong><?php echo esc_html( Jalali::format( gmdate( 'Y-m-d H:i:s', $last_run ), 'datetime' ) ); ?></strong>
			<?php else : ?>
				هنوز اجرا نشده است. اولین اجرا فقط تصویر موجودی می‌گیرد و هیچ اعلانی نمی‌فرستد.
			<?php endif; ?>
		</p>

	</div>
	<div class="nvb-panel">
		<h2>نرخ تبدیل لیست انتظار به سفارش</h2>
		<?php if ( empty( $conversion['available'] ) ) : ?>
			<p class="nvb-muted">
				داده در دسترس نیست — هنوز هیچ اعلانی ارسال نشده یا ووکامرس در دسترس نیست.
			</p>
		<?php else : ?>
			<p>
				از <strong><?php echo esc_html( Persian::number( $conversion['notified'] ) ); ?></strong> مشترک اعلان‌شده،
				<strong><?php echo esc_html( Persian::number( $conversion['converted'] ) ); ?></strong> مورد سفارش ثبت کرده‌اند
				(نمونهٔ حداکثر ۲۰۰ ردیف اخیر): <strong><?php echo esc_html( Persian::number( $conversion['rate'] ) ); ?>٪</strong>
			</p>
			<p class="nvb-muted">این عدد بر پایهٔ تطابق ایمیل/موبایل صورتحساب سفارش محاسبه می‌شود؛ اگر مشتری با حساب دیگری خرید کند شمرده نمی‌شود.</p>
		<?php endif; ?>

	</div>
	<div class="nvb-panel">
		<h2>ساختار پایگاه داده</h2>
		<table class="widefat striped nvb-table">
			<thead>
				<tr><th>جدول</th><th>موتور</th></tr>
			</thead>
			<tbody>
				<?php foreach ( $tables as $name => $engine ) : ?>
					<tr>
						<td><?php echo esc_html( $name ); ?></td>
						<td>
							<?php if ( 'InnoDB' === $engine ) : ?>
								<span class="nvb-ok">InnoDB</span>
							<?php else : ?>
								<span class="nvb-bad"><?php echo esc_html( 'missing' === $engine ? 'ساخته نشده' : $engine ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

	</div>
	<?php $this->close_screen(); ?>
