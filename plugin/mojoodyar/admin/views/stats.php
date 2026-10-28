<?php
/**
 * Statistics screen.
 *
 * Every figure is handed in by Stats::report(); this file only formats.
 * Two rules it keeps on purpose:
 *   - an empty period says so in words instead of drawing an empty grid, so a
 *     shop that just installed the plugin never sees a "broken chart";
 *   - nothing here loads an external chart library. Bars are divs sized by a
 *     percentage, which keeps the admin screen offline-safe and light.
 *
 * @var array $report Stats::report() output.
 * @var int   $range  Active period in days.
 *
 * @package Mojoodyar
 */

defined( 'ABSPATH' ) || exit;

use Mojoodyar\Logic\StatsMath;
use Mojoodyar\Support\Jalali;
use Mojoodyar\Support\Persian;

$mjy_sum    = $report['summary'];
$mjy_sign   = $report['signups'];
$mjy_notif  = $report['notified'];
$mjy_step   = StatsMath::label_step( (int) $range );
$mjy_peak_s = (int) $report['peak']['signups'];
$mjy_peak_n = (int) $report['peak']['notified'];

$mjy_status_labels = array(
	'pending'      => 'در انتظار تأیید',
	'confirmed'    => 'تأییدشده',
	'notified'     => 'اعلان‌شده',
	'unsubscribed' => 'لغو اشتراک',
	'expired'      => 'منقضی',
);

$mjy_call_labels = array(
	'answered'   => 'پاسخ داد',
	'no_answer'  => 'پاسخ نداد',
	'busy'       => 'مشغول بود',
	'callback'   => 'بعداً تماس بگیرم',
	'ordered'    => 'سفارش داد',
	'wrong'      => 'شماره اشتباه بود',
	'unsub'      => 'درخواست لغو کرد',
	''           => 'بدون نتیجه',
);

$mjy_day_label = static function ( $key ) {
	$parts = explode( '-', (string) $key );

	if ( count( $parts ) !== 3 ) {
		return '';
	}

	$j = Jalali::from_gregorian( (int) $parts[0], (int) $parts[1], (int) $parts[2] );

	return Persian::digits( $j[2] ) . ' ' . Jalali::MONTHS[ $j[1] - 1 ];
};
?>
<?php $this->open_screen( 'stats', 'موجودیار — آمار', 'آنچه در بازهٔ انتخابی گذشته است، با عدد' ); ?>

	<div class="mjy-ranges no-print">
		<?php foreach ( \Mojoodyar\Stats::RANGES as $mjy_r ) : ?>
			<a
				class="mjy-range<?php echo ( (int) $mjy_r === (int) $range ) ? ' is-active' : ''; ?>"
				href="<?php echo esc_url( admin_url( 'admin.php?page=mojoodyar-stats&range=' . (int) $mjy_r ) ); ?>"
				<?php echo ( (int) $mjy_r === (int) $range ) ? 'aria-current="page"' : ''; ?>
			><?php echo esc_html( Persian::number( $mjy_r ) ); ?> روز</a>
		<?php endforeach; ?>

		<a class="button mjy-range-export" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mojoodyar_export_stats&range=' . (int) $range ), 'mojoodyar_export_stats' ) ); ?>">خروجی CSV آمار</a>
	</div>

	<p class="mjy-quicklinks">
		<span>اگر فرم را پر می‌کنید و ردیفی در لیست انتظار ساخته نمی‌شود:</span>
		<a class="button button-secondary" href="#mjy-rejections">ثبت‌های ردشده — همین صفحه</a>
		<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=mojoodyar-logs&level=security' ) ); ?>">گزارش کامل رویدادها</a>
	</p>

	<div class="mjy-grid">
		<div class="mjy-card">
			<span class="mjy-card__label">ثبت تازه در این بازه</span>
			<strong class="mjy-card__value"><?php echo esc_html( Persian::number( $mjy_sum['total'] ) ); ?></strong>
			<span class="mjy-card__hint">میانگین روزانه <?php echo esc_html( Persian::digits( number_format( (float) $mjy_sum['per_day'], 1, '.', '' ) ) ); ?></span>
		</div>
		<div class="mjy-card">
			<span class="mjy-card__label">تأییدشده یا اعلان‌شده</span>
			<strong class="mjy-card__value"><?php echo esc_html( Persian::number( $mjy_sum['confirm_rate'] ) ); ?>٪</strong>
			<span class="mjy-card__hint">از <?php echo esc_html( Persian::number( $mjy_sum['total'] ) ); ?> ثبت این بازه</span>
		</div>
		<div class="mjy-card">
			<span class="mjy-card__label">اعلان‌شده در این بازه</span>
			<strong class="mjy-card__value"><?php echo esc_html( Persian::number( $mjy_sum['notified'] ) ); ?></strong>
			<span class="mjy-card__hint">مشتریانی که خبر گرفتند</span>
		</div>
		<div class="mjy-card">
			<span class="mjy-card__label">پیام ارسال‌شده از صف</span>
			<strong class="mjy-card__value"><?php echo esc_html( Persian::number( $mjy_sum['sent'] ) ); ?></strong>
			<span class="mjy-card__hint">
				<?php if ( (int) $mjy_sum['failed'] > 0 ) : ?>
					<?php echo esc_html( Persian::number( $mjy_sum['failed'] ) ); ?> ناموفق — ابزارها → گزارش رویدادها
				<?php else : ?>
					هیچ ارسال ناموفقی ثبت نشده
				<?php endif; ?>
			</span>
		</div>
	</div>

	<div class="mjy-panel">
		<h2>ثبت درخواست در هر روز</h2>

		<?php if ( 0 === $mjy_peak_s ) : ?>
			<p class="mjy-muted">
				در <?php echo esc_html( Persian::number( $range ) ); ?> روز گذشته هیچ ثبت تازه‌ای نبوده است.
				برای دیدن علت، بخش «ثبت‌های ردشده» را در همین صفحه ببینید.
			</p>
		<?php else : ?>
			<div class="mjy-chart" role="img" aria-label="نمودار ثبت درخواست در هر روز">
				<?php foreach ( $mjy_sign['values'] as $mjy_i => $mjy_v ) : ?>
					<div class="mjy-chart__col" title="<?php echo esc_attr( $mjy_day_label( $mjy_sign['keys'][ $mjy_i ] ) . ' — ' . Persian::number( $mjy_v ) ); ?>">
						<div class="mjy-chart__bar" style="height:<?php echo (int) StatsMath::height( $mjy_v, $mjy_peak_s ); ?>%">
							<span class="mjy-chart__value"><?php echo (int) $mjy_v > 0 ? esc_html( Persian::number( $mjy_v ) ) : ''; ?></span>
						</div>
						<?php if ( 0 === ( $mjy_i % $mjy_step ) || $mjy_i === count( $mjy_sign['values'] ) - 1 ) : ?>
							<em class="mjy-chart__label"><?php echo esc_html( $mjy_day_label( $mjy_sign['keys'][ $mjy_i ] ) ); ?></em>
						<?php else : ?>
							<em class="mjy-chart__label is-empty">&nbsp;</em>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="mjy-panel">
		<h2>اعلان در هر روز</h2>

		<?php if ( 0 === $mjy_peak_n ) : ?>
			<p class="mjy-muted">
				در این بازه هیچ اعلانی به مشتری نرسیده است. اگر لیست انتظار خالی نیست،
				ابزارها → «چرا برای این محصول پیامی نرفت؟» را با شناسهٔ محصول اجرا کنید.
			</p>
		<?php else : ?>
			<div class="mjy-chart mjy-chart--alt" role="img" aria-label="نمودار اعلان در هر روز">
				<?php foreach ( $mjy_notif['values'] as $mjy_i => $mjy_v ) : ?>
					<div class="mjy-chart__col" title="<?php echo esc_attr( $mjy_day_label( $mjy_notif['keys'][ $mjy_i ] ) . ' — ' . Persian::number( $mjy_v ) ); ?>">
						<div class="mjy-chart__bar" style="height:<?php echo (int) StatsMath::height( $mjy_v, $mjy_peak_n ); ?>%">
							<span class="mjy-chart__value"><?php echo (int) $mjy_v > 0 ? esc_html( Persian::number( $mjy_v ) ) : ''; ?></span>
						</div>
						<?php if ( 0 === ( $mjy_i % $mjy_step ) || $mjy_i === count( $mjy_notif['values'] ) - 1 ) : ?>
							<em class="mjy-chart__label"><?php echo esc_html( $mjy_day_label( $mjy_notif['keys'][ $mjy_i ] ) ); ?></em>
						<?php else : ?>
							<em class="mjy-chart__label is-empty">&nbsp;</em>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="mjy-split">
		<div class="mjy-panel">
			<h2>کانال اعلان</h2>
			<?php if ( (int) $mjy_sum['total'] <= 0 ) : ?>
				<p class="mjy-muted">داده‌ای در این بازه نیست.</p>
			<?php else : ?>
				<table class="widefat striped mjy-table">
					<tbody>
						<?php foreach ( array( 'sms' => 'پیامک', 'email' => 'ایمیل' ) as $mjy_key => $mjy_label ) : ?>
							<?php
							$mjy_count = isset( $report['channels'][ $mjy_key ] ) ? (int) $report['channels'][ $mjy_key ] : 0;
							$mjy_pct   = StatsMath::percent( $mjy_count, (int) $mjy_sum['total'] );
							?>
							<tr>
								<th><?php echo esc_html( $mjy_label ); ?></th>
								<td><?php echo esc_html( Persian::number( $mjy_count ) ); ?></td>
								<td class="mjy-share">
									<span class="mjy-share__bar" style="width:<?php echo (int) $mjy_pct; ?>%"></span>
									<span class="mjy-share__text"><?php echo esc_html( Persian::number( $mjy_pct ) ); ?>٪</span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="mjy-panel">
			<h2>وضعیت فعلی همهٔ ردیف‌ها</h2>
			<?php if ( empty( $report['statuses'] ) ) : ?>
				<p class="mjy-muted">هنوز هیچ ردیفی در لیست انتظار نیست.</p>
			<?php else : ?>
				<table class="widefat striped mjy-table">
					<tbody>
						<?php foreach ( $mjy_status_labels as $mjy_key => $mjy_label ) : ?>
							<tr>
								<th><?php echo esc_html( $mjy_label ); ?></th>
								<td><?php echo esc_html( Persian::number( isset( $report['statuses'][ $mjy_key ] ) ? $report['statuses'][ $mjy_key ] : 0 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<div class="mjy-panel">
		<h2>پُرتکرارترین محصول‌ها در این بازه</h2>

		<?php if ( empty( $report['products'] ) ) : ?>
			<p class="mjy-muted">در این بازه درخواستی ثبت نشده است.</p>
		<?php else : ?>
			<table class="widefat striped mjy-table">
				<thead>
					<tr>
						<th>محصول</th>
						<th>درخواست</th>
						<th>هنوز منتظر</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $report['products'] as $mjy_row ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=mojoodyar-waitlists&product_id=' . (int) $mjy_row['product_id'] ) ); ?>">
									<?php echo esc_html( $mjy_row['name'] ); ?>
								</a>
								<?php if ( (int) $mjy_row['variation_id'] > 0 ) : ?>
									<span class="mjy-muted">— تنوع #<?php echo esc_html( Persian::digits( (int) $mjy_row['variation_id'] ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( Persian::number( $mjy_row['total'] ) ); ?></td>
							<td><?php echo esc_html( Persian::number( $mjy_row['awaiting'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="mjy-split">
		<div class="mjy-panel">
			<h2>نتیجهٔ تماس‌های پیگیری</h2>
			<?php if ( empty( $report['calls'] ) ) : ?>
				<p class="mjy-muted">در این بازه تماسی ثبت نشده است.</p>
			<?php else : ?>
				<table class="widefat striped mjy-table">
					<tbody>
						<?php foreach ( $report['calls'] as $mjy_key => $mjy_count ) : ?>
							<tr>
								<th><?php echo esc_html( isset( $mjy_call_labels[ $mjy_key ] ) ? $mjy_call_labels[ $mjy_key ] : $mjy_key ); ?></th>
								<td><?php echo esc_html( Persian::number( $mjy_count ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="mjy-panel mjy-panel--warn" id="mjy-rejections">
			<h2>ثبت‌های ردشده — چرا فرم ردیف نمی‌سازد</h2>

			<?php if ( empty( $report['rejections'] ) ) : ?>
				<p class="mjy-muted">
					در این بازه هیچ درخواستی رد نشده است. اگر فرم را پر می‌کنید و ردیفی
					ساخته نمی‌شود، این بخش باید خالی نباشد؛ خالی‌بودنش یعنی درخواست به
					افزونه نرسیده (خطای شبکه یا مسدودشدن مسیر امن).
				</p>
			<?php else : ?>
				<p class="mjy-muted">این درخواست‌ها هیچ ردیفی در لیست انتظار نمی‌سازند — دقیقاً همان چیزی که به‌نظر می‌رسد «ثبت نشده است».</p>
				<table class="widefat striped mjy-table">
					<tbody>
						<?php foreach ( $report['rejections'] as $mjy_row ) : ?>
							<tr>
								<th><?php echo esc_html( $mjy_row['label'] ); ?></th>
								<td><?php echo esc_html( Persian::number( $mjy_row['count'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="mjy-log__more">
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=mojoodyar-logs&level=security' ) ); ?>">
						دیدن هر ردیف با تاریخ، متن لاگ و هش IP
					</a>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<div class="mjy-panel">
		<h2>سلامت صف ارسال</h2>
		<table class="widefat striped mjy-table">
			<tbody>
				<tr><th>در صف</th><td><?php echo esc_html( Persian::number( $report['queue']['queued'] ) ); ?></td></tr>
				<tr><th>در حال ارسال</th><td><?php echo esc_html( Persian::number( $report['queue']['processing'] ) ); ?></td></tr>
				<tr><th>ارسال‌شده (کل)</th><td><?php echo esc_html( Persian::number( $report['queue']['sent'] ) ); ?></td></tr>
				<tr><th>ناموفق</th><td><?php echo esc_html( Persian::number( $report['queue']['failed'] ) ); ?></td></tr>
				<tr><th>ردشده</th><td><?php echo esc_html( Persian::number( $report['queue']['skipped'] ) ); ?></td></tr>
			</tbody>
		</table>
		<p class="mjy-muted">
			عددهای این صفحه از جدول‌های خود افزونه خوانده می‌شوند و با هر بازکردن صفحه
			دوباره محاسبه می‌شوند؛ دادهٔ نمونه در آن‌ها نیست.
		</p>
	</div>

<?php $this->close_screen(); ?>
