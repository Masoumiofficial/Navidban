<?php
/**
 * Log screen.
 *
 * Deliberately two-column per row: the left column is the Persian sentence a
 * shopkeeper can act on, and the right is the untouched raw row (event key,
 * context, IP hash) for support. Hiding the raw value would make tickets
 * impossible; hiding the Persian one would make the screen useless.
 *
 * @var array $summary  Counters.
 * @var array $result   Paged rows.
 * @var array $events   Event keys present in the table.
 * @var array $filters  Active filter values.
 *
 * @package Mojoodyar
 */

defined( 'ABSPATH' ) || exit;

use Mojoodyar\Logic\LogExplain;
use Mojoodyar\Support\Jalali;
use Mojoodyar\Support\Persian;

$mjy_log_label = array(
	'security' => 'امنیتی',
	'error'    => 'خطا',
	'warning'  => 'هشدار',
	'info'     => 'اطلاع',
);

$mjy_base_url = 'admin.php?page=mojoodyar-logs';

$mjy_link = static function ( array $overrides ) use ( $mjy_base_url, $filters ) {
	$query = array_merge(
		array(
			'page'   => 'mojoodyar-logs',
			'level'  => (string) $filters['level'],
			'event'  => (string) $filters['event'],
			's'      => (string) $filters['search'],
			'days'   => (int) $filters['days'],
			'paged'  => 1,
		),
		$overrides
	);

	return admin_url( 'admin.php?' . http_build_query( array_filter( $query, static function ( $value ) {
		return '' !== $value && 0 !== $value;
	} ) ) );
};
?>
<?php $this->open_screen( 'logs', 'موجودیار — گزارش رویدادها', 'همهٔ وقایع افزونه، با توضیح فارسی و لاگ اصلی' ); ?>

	<div class="mjy-grid">
		<div class="mjy-card">
			<span class="mjy-card__label">کل رویدادها</span>
			<strong class="mjy-card__value"><?php echo esc_html( Persian::number( $summary['total'] ) ); ?></strong>
			<span class="mjy-card__hint"><?php echo esc_html( Persian::number( $summary['today'] ) ); ?> مورد در ۲۴ ساعت گذشته</span>
		</div>
		<div class="mjy-card">
			<span class="mjy-card__label">هشدار و خطا</span>
			<strong class="mjy-card__value"><?php echo esc_html( Persian::number( $summary['problems'] ) ); ?></strong>
			<span class="mjy-card__hint">چیزی که ارزش دیدن دارد</span>
		</div>
		<div class="mjy-card">
			<span class="mjy-card__label">رویداد امنیتی</span>
			<strong class="mjy-card__value"><?php echo esc_html( Persian::number( $summary['security'] ) ); ?></strong>
			<span class="mjy-card__hint">درخواست ردشده، محدودیت نرخ، کد امنیتی</span>
		</div>
		<div class="mjy-card">
			<span class="mjy-card__label">خطای ارسال</span>
			<strong class="mjy-card__value"><?php echo esc_html( Persian::number( $summary['errors'] ) ); ?></strong>
			<span class="mjy-card__hint">درگاه یا پایگاه داده</span>
		</div>
	</div>

	<form method="get" class="mjy-filters">
		<input type="hidden" name="page" value="mojoodyar-logs" />

		<label>سطح
			<select name="level">
				<option value="">همه</option>
				<?php foreach ( \Mojoodyar\Logs::FILTER_LEVELS as $mjy_level ) : ?>
					<option value="<?php echo esc_attr( $mjy_level ); ?>" <?php selected( (string) $filters['level'], $mjy_level ); ?>>
						<?php echo esc_html( $mjy_log_label[ $mjy_level ] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label>رویداد
			<select name="event">
				<option value="">همه</option>
				<?php foreach ( $events as $mjy_event ) : ?>
					<option value="<?php echo esc_attr( $mjy_event['event'] ); ?>" <?php selected( (string) $filters['event'], (string) $mjy_event['event'] ); ?>>
						<?php echo esc_html( $mjy_event['label'] . ' (' . Persian::number( $mjy_event['count'] ) . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label>بازه
			<select name="days">
				<option value="0">همه</option>
				<?php foreach ( array( 1 => '۲۴ ساعت', 7 => '۷ روز', 30 => '۳۰ روز' ) as $mjy_days => $mjy_days_label ) : ?>
					<option value="<?php echo (int) $mjy_days; ?>" <?php selected( (int) $filters['days'], (int) $mjy_days ); ?>>
						<?php echo esc_html( $mjy_days_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label>جست‌وجو در متن لاگ
			<input type="search" name="s" value="<?php echo esc_attr( (string) $filters['search'] ); ?>" placeholder="مثلاً bad_nonce یا 501" />
		</label>

		<button class="button">اعمال فیلتر</button>
		<a class="button button-secondary" href="<?php echo esc_url( admin_url( $mjy_base_url ) ); ?>">پاک کردن</a>
		<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mojoodyar_export_logs&' . http_build_query( array_filter( array(
			'level' => (string) $filters['level'],
			'event' => (string) $filters['event'],
			's'     => (string) $filters['search'],
			'days'  => (int) $filters['days'],
		), static function ( $value ) {
			return '' !== $value && 0 !== $value;
		} ) ) ), 'mojoodyar_export_logs' ) ); ?>">خروجی CSV لاگ</a>
	</form>

	<p class="mjy-muted">
		<?php echo esc_html( Persian::number( $result['total'] ) ); ?> رویداد
		<?php if ( (int) $result['pages'] > 1 ) : ?>
			— صفحهٔ <?php echo esc_html( Persian::number( $result['page'] ) ); ?> از <?php echo esc_html( Persian::number( $result['pages'] ) ); ?>
		<?php endif; ?>
		· آدرس و ایمیل هرگز خام ذخیره نمی‌شوند؛ فقط هش IP و کلید رویداد.
	</p>

	<?php if ( empty( $result['rows'] ) ) : ?>
		<div class="mjy-panel">
			<h2>رویدادی با این فیلتر پیدا نشد</h2>
			<p class="mjy-muted">
				اگر انتظار داشتید این‌جا چیزی باشد، فیلتر را روی «همه» بگذارید. اگر جدول
				کاملاً خالی است، یا افزونه تازه نصب شده، یا نگهداری لاگ خیلی کوتاه است.
			</p>
		</div>
	<?php else : ?>
		<div class="mjy-log">
			<?php foreach ( $result['rows'] as $mjy_row ) : ?>
				<?php
				$mjy_level     = (string) $mjy_row['level'];
				$mjy_event_key = (string) $mjy_row['event'];
				$mjy_context   = (string) $mjy_row['context'];
				$mjy_advice    = LogExplain::advice( $mjy_event_key, $mjy_context );
				?>
				<article class="mjy-log__row mjy-log__row--<?php echo esc_attr( $mjy_level ); ?>">
					<div class="mjy-log__main">
						<div class="mjy-log__head">
							<span class="mjy-log__level mjy-log__level--<?php echo esc_attr( $mjy_level ); ?>">
								<?php echo esc_html( isset( $mjy_log_label[ $mjy_level ] ) ? $mjy_log_label[ $mjy_level ] : $mjy_level ); ?>
							</span>
							<strong class="mjy-log__title"><?php echo esc_html( LogExplain::event( $mjy_event_key ) ); ?></strong>
							<span class="mjy-log__time">
								<?php echo esc_html( Jalali::format( (string) $mjy_row['created_at'], 'Y/m/d H:i' ) ); ?>
								<span class="mjy-muted">· <?php echo esc_html( Persian::relative( max( 0, time() - (int) strtotime( (string) $mjy_row['created_at'] . ' UTC' ) ) ) ); ?></span>
							</span>
						</div>

						<p class="mjy-log__detail"><?php echo esc_html( LogExplain::detail( $mjy_event_key, $mjy_context ) ); ?></p>

						<?php if ( '' !== $mjy_advice ) : ?>
							<p class="mjy-log__advice">
								<span aria-hidden="true">↳</span>
								<?php echo esc_html( $mjy_advice ); ?>
							</p>
						<?php endif; ?>
					</div>

					<div class="mjy-log__raw">
						<details>
							<summary>لاگ اصلی</summary>
							<dl>
								<dt>event</dt>
								<dd dir="ltr"><?php echo esc_html( $mjy_event_key ); ?></dd>
								<dt>level</dt>
								<dd dir="ltr"><?php echo esc_html( $mjy_level ); ?></dd>
								<dt>context</dt>
								<dd dir="ltr"><?php echo '' === $mjy_context ? '—' : esc_html( $mjy_context ); ?></dd>
								<dt>id</dt>
								<dd dir="ltr"><?php echo (int) $mjy_row['id']; ?></dd>
								<?php if ( ! empty( $mjy_row['ip_hash'] ) ) : ?>
									<dt>ip_hash</dt>
									<dd dir="ltr"><?php echo esc_html( substr( (string) $mjy_row['ip_hash'], 0, 12 ) ); ?>…</dd>
								<?php endif; ?>
							</dl>
						</details>
					</div>
				</article>
			<?php endforeach; ?>
		</div>

		<?php if ( (int) $result['pages'] > 1 ) : ?>
			<div class="mjy-log__pager">
				<?php if ( (int) $result['page'] > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( $mjy_link( array( 'paged' => (int) $result['page'] - 1 ) ) ); ?>">قبلی</a>
				<?php endif; ?>
				<span class="mjy-muted">صفحهٔ <?php echo esc_html( Persian::number( $result['page'] ) ); ?> از <?php echo esc_html( Persian::number( $result['pages'] ) ); ?></span>
				<?php if ( (int) $result['page'] < (int) $result['pages'] ) : ?>
					<a class="button" href="<?php echo esc_url( $mjy_link( array( 'paged' => (int) $result['page'] + 1 ) ) ); ?>">بعدی</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="mjy-panel mjy-panel--warn">
		<h2>پاک کردن لاگ‌ها</h2>
		<p class="mjy-muted">
			لاگ‌ها به‌صورت خودکار هر ۹۰ روز پاک می‌شوند (قابل تغییر در تنظیمات → پیشرفته).
			پاک کردن دستی برگشت‌پذیر نیست و برای پیگیری تیکت پشتیبانی لازم است.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('همهٔ لاگ‌ها پاک شوند؟ این کار برگشت‌پذیر نیست.');">
			<?php wp_nonce_field( 'mojoodyar_tool' ); ?>
			<input type="hidden" name="action" value="mojoodyar_tool" />
			<input type="hidden" name="tool" value="clear_logs" />
			<button class="button" type="submit">پاک کردن همهٔ لاگ‌ها</button>
		</form>
	</div>

<?php $this->close_screen(); ?>
