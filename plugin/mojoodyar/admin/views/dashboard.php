<?php
/**
 * Dashboard view.
 *
 * @var array $counts     Waitlist counters.
 * @var array $queue      Queue counters.
 * @var int   $last_run   Last reconcile timestamp.
 * @var array $tables     Table engines.
 * @var array $conversion Conversion stats.
 * @var array $problems   Newest warnings/errors/security events.
 * @var array $log_counts Log counters.
 *
 * @package Mojoodyar
 */

defined( 'ABSPATH' ) || exit;

use Mojoodyar\Support\Jalali;
use Mojoodyar\Support\Persian;

$mjy_healthy = ! in_array( 'missing', $tables, true ) && ! in_array( 'MyISAM', $tables, true );
?>
<?php $this->open_screen( 'dashboard', 'موجودیار — داشبورد', 'نگهبانِ خبرهای خوب فروشگاه شما' ); ?>

	<?php if ( ! $mjy_healthy ) : ?>
		<div class="notice notice-error inline">
			<p><strong>ساختار پایگاه داده کامل نیست.</strong> صفحهٔ «ابزارها» را باز کنید و «بازسازی جدول‌ها» را بزنید.</p>
		</div>
	<?php endif; ?>

	<div class="mjy-grid">
		<div class="mjy-card">
			<span class="mjy-card__label">در انتظار تأیید</span>
			<strong class="mjy-card__value"><?php echo esc_html( Persian::number( $counts['pending'] ) ); ?></strong>
		</div>
		<div class="mjy-card">
			<span class="mjy-card__label">تأییدشده و در صف</span>
			<strong class="mjy-card__value"><?php echo esc_html( Persian::number( $counts['confirmed'] ) ); ?></strong>
		</div>
		<div class="mjy-card">
			<span class="mjy-card__label">اعلان‌شده</span>
			<strong class="mjy-card__value"><?php echo esc_html( Persian::number( $counts['notified'] ) ); ?></strong>
		</div>
		<div class="mjy-card">
			<span class="mjy-card__label">تماس گرفته‌شده</span>
			<strong class="mjy-card__value"><?php echo esc_html( Persian::number( $counts['called'] ) ); ?></strong>
		</div>
		<div class="mjy-card">
			<span class="mjy-card__label">لغو اشتراک</span>
			<strong class="mjy-card__value"><?php echo esc_html( Persian::number( $counts['unsubscribed'] ) ); ?></strong>
		</div>
	</div>

	<div class="mjy-panel">
		<h2>وضعیت صف ارسال</h2>
		<table class="widefat striped mjy-table">
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
	<div class="mjy-panel<?php echo empty( $problems ) ? '' : ' mjy-panel--warn'; ?>">
		<h2>آخرین رویدادهایی که باید ببینید</h2>

		<?php if ( empty( $problems ) ) : ?>
			<p class="mjy-muted">
				هیچ هشدار، خطا یا درخواست ردشده‌ای ثبت نشده است. این خوب است — و یعنی
				اگر مشتری می‌گوید «ثبت نشد»، درخواستش به افزونه نرسیده است.
			</p>
		<?php else : ?>
			<ul class="mjy-events">
				<?php foreach ( $problems as $problem ) : ?>
					<li class="mjy-events__item mjy-events__item--<?php echo esc_attr( (string) $problem['level'] ); ?>">
						<span class="mjy-events__level"><?php echo esc_html( \Mojoodyar\Logic\LogExplain::level( (string) $problem['level'] ) ); ?></span>
						<span class="mjy-events__text">
							<strong><?php echo esc_html( \Mojoodyar\Logic\LogExplain::event( (string) $problem['event'] ) ); ?></strong>
							<em><?php echo esc_html( \Mojoodyar\Logic\LogExplain::detail( (string) $problem['event'], (string) $problem['context'] ) ); ?></em>
						</span>
						<span class="mjy-events__time"><?php echo esc_html( Jalali::format( (string) $problem['created_at'], 'Y/m/d H:i' ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p class="mjy-log__more">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mojoodyar-logs' ) ); ?>">گزارش کامل رویدادها</a>
			<?php if ( (int) $log_counts['security'] > 0 ) : ?>
				<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=mojoodyar-logs&level=security' ) ); ?>">
					<?php echo esc_html( Persian::number( $log_counts['security'] ) ); ?> درخواست ردشده
				</a>
			<?php endif; ?>
		</p>
	</div>

	<div class="mjy-panel">
		<h2>پایش تطبیقی</h2>
		<p>
			<?php if ( $last_run > 0 ) : ?>
				آخرین اجرا: <strong><?php echo esc_html( Jalali::format( gmdate( 'Y-m-d H:i:s', $last_run ), 'datetime' ) ); ?></strong>
			<?php else : ?>
				هنوز اجرا نشده است. اولین اجرا فقط تصویر موجودی می‌گیرد و هیچ اعلانی نمی‌فرستد.
			<?php endif; ?>
		</p>

	</div>
	<div class="mjy-panel">
		<h2>نرخ تبدیل لیست انتظار به سفارش</h2>
		<?php if ( empty( $conversion['available'] ) ) : ?>
			<p class="mjy-muted">
				داده در دسترس نیست — هنوز هیچ اعلانی ارسال نشده یا ووکامرس در دسترس نیست.
			</p>
		<?php else : ?>
			<p>
				از <strong><?php echo esc_html( Persian::number( $conversion['notified'] ) ); ?></strong> مشترک اعلان‌شده،
				<strong><?php echo esc_html( Persian::number( $conversion['converted'] ) ); ?></strong> مورد سفارش ثبت کرده‌اند
				(نمونهٔ حداکثر ۲۰۰ ردیف اخیر): <strong><?php echo esc_html( Persian::number( $conversion['rate'] ) ); ?>٪</strong>
			</p>
			<p class="mjy-muted">این عدد بر پایهٔ تطابق ایمیل/موبایل صورتحساب سفارش محاسبه می‌شود؛ اگر مشتری با حساب دیگری خرید کند شمرده نمی‌شود.</p>
		<?php endif; ?>

	</div>
	<div class="mjy-panel">
		<h2>ساختار پایگاه داده</h2>
		<table class="widefat striped mjy-table">
			<thead>
				<tr><th>جدول</th><th>موتور</th></tr>
			</thead>
			<tbody>
				<?php foreach ( $tables as $name => $engine ) : ?>
					<tr>
						<td><?php echo esc_html( $name ); ?></td>
						<td>
							<?php if ( 'InnoDB' === $engine ) : ?>
								<span class="mjy-ok">InnoDB</span>
							<?php else : ?>
								<span class="mjy-bad"><?php echo esc_html( 'missing' === $engine ? 'ساخته نشده' : $engine ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

	</div>
	<?php $this->close_screen(); ?>
