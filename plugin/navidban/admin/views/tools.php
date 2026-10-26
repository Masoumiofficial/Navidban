<?php
/**
 * Tools view.
 *
 * @var array $tables   Table engines.
 * @var array $queue    Queue counters.
 * @var int   $last_run Last reconcile timestamp.
 *
 * @package Navidban
 */

defined( 'ABSPATH' ) || exit;

use Navidban\Support\Jalali;
use Navidban\Support\Persian;

$nvb_notice = get_transient( 'navidban_tool_notice' );
if ( $nvb_notice ) {
	delete_transient( 'navidban_tool_notice' );
}
?>
<div class="wrap nvb-wrap" dir="rtl">
	<h1>ابزارهای نویدبان</h1>

	<?php if ( $nvb_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( (string) $nvb_notice ); ?></p></div>
	<?php endif; ?>

	<p class="nvb-muted">
		آخرین پایش: <?php echo $last_run > 0 ? esc_html( Jalali::format( gmdate( 'Y-m-d H:i:s', $last_run ), 'datetime' ) ) : 'هرگز'; ?>
		· پیام در صف: <?php echo esc_html( Persian::number( $queue['queued'] ) ); ?>
	</p>

	<div class="nvb-grid">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nvb-card">
			<?php wp_nonce_field( 'navidban_tool' ); ?>
			<input type="hidden" name="action" value="navidban_tool" />
			<input type="hidden" name="tool" value="rebuild_snapshots" />
			<h3>بازسازی تصویر موجودی</h3>
			<p>همهٔ ردیف‌های تصویر پاک و از وضعیت فعلی محصولات بازسازی می‌شود. هیچ اعلانی ارسال نمی‌شود.</p>
			<button class="button button-primary">بازسازی</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nvb-card">
			<?php wp_nonce_field( 'navidban_tool' ); ?>
			<input type="hidden" name="action" value="navidban_tool" />
			<input type="hidden" name="tool" value="run_reconcile" />
			<h3>اجرای فوری پایش</h3>
			<p>فاصلهٔ زمانی نادیده گرفته می‌شود و همین حالا یک پاس کامل انجام می‌شود.</p>
			<button class="button button-primary">اجرا</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nvb-card">
			<?php wp_nonce_field( 'navidban_tool' ); ?>
			<input type="hidden" name="action" value="navidban_tool" />
			<input type="hidden" name="tool" value="flush_queue" />
			<h3>پردازش صف تا سقف ۲۰۰ پیام</h3>
			<p>برای وقتی که WP-Cron روی هاست شما اجرا نمی‌شود.</p>
			<button class="button">پردازش</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nvb-card">
			<?php wp_nonce_field( 'navidban_tool' ); ?>
			<input type="hidden" name="action" value="navidban_tool" />
			<input type="hidden" name="tool" value="empty_queue" />
			<h3>تخلیهٔ صف</h3>
			<p>همهٔ پیام‌های در انتظار، ناموفق و ردشده حذف می‌شوند. رکوردهای لیست انتظار دست‌نخورده می‌مانند.</p>
			<button class="button button-secondary">تخلیه</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nvb-card">
			<?php wp_nonce_field( 'navidban_tool' ); ?>
			<input type="hidden" name="action" value="navidban_tool" />
			<input type="hidden" name="tool" value="prune" />
			<h3>پاک‌سازی رکوردهای قدیمی</h3>
			<p>ردیف‌های اعلان‌شده/منقضی/لغوشدهٔ قدیمی‌تر از سیاست نگهداری حذف می‌شوند.</p>
			<button class="button button-secondary">پاک‌سازی</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nvb-card">
			<?php wp_nonce_field( 'navidban_tool' ); ?>
			<input type="hidden" name="action" value="navidban_tool" />
			<input type="hidden" name="tool" value="test_email" />
			<h3>ارسال ایمیل آزمایشی</h3>
			<p>یک پیام آزمایشی به ایمیل مدیر سایت ارسال می‌شود.</p>
			<?php
			$nvb_mail_facts = \Navidban\Channels\EmailChannel::diagnose();
			$nvb_mail_hint  = \Navidban\Channels\EmailChannel::hint();
			?>
			<p class="nvb-diagnosis">
				<strong>وضعیت ارسال ایمیل روی این سرور:</strong>
				تابع <code>mail()</code>: <?php echo esc_html( 'available' === $nvb_mail_facts['mail_function'] ? 'در دسترس' : 'غیرفعال' ); ?>
				· <code>sendmail_path</code>: <?php echo esc_html( '' === (string) $nvb_mail_facts['sendmail_path'] ? 'خالی' : (string) $nvb_mail_facts['sendmail_path'] ); ?>
				· <code>SMTP</code>: <?php echo esc_html( '' === (string) $nvb_mail_facts['smtp_host'] ? 'خالی' : (string) $nvb_mail_facts['smtp_host'] . ':' . (string) $nvb_mail_facts['smtp_port'] ); ?>
				<?php if ( '' !== (string) $nvb_mail_facts['mailer_plugin'] ) : ?>
					· افزونهٔ SMTP: <?php echo esc_html( (string) $nvb_mail_facts['mailer_plugin'] ); ?>
				<?php endif; ?>
				· فرستنده: <?php echo esc_html( '' === (string) $nvb_mail_facts['from_email'] ? 'تنظیم نشده' : (string) $nvb_mail_facts['from_email'] ); ?>
			</p>
			<?php if ( '' !== $nvb_mail_hint ) : ?>
				<p class="nvb-diagnosis nvb-diagnosis--warn"><?php echo esc_html( $nvb_mail_hint ); ?></p>
			<?php endif; ?>
			<button class="button">ارسال آزمایشی</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nvb-card">
			<?php wp_nonce_field( 'navidban_tool' ); ?>
			<input type="hidden" name="action" value="navidban_tool" />
			<input type="hidden" name="tool" value="test_sms" />
			<h3>ارسال پیامک آزمایشی</h3>
			<p>شماره را وارد کنید و ارسال را آزمایش کنید.</p>
			<input dir="ltr" name="test_phone" placeholder="09xxxxxxxxx" />
			<button class="button">ارسال آزمایشی</button>
		</form>
	</div>

<<<<<<< HEAD
=======
	<h2>چرا برای این محصول پیامی نرفت؟</h2>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nvb-card">
		<input type="hidden" name="page" value="<?php echo esc_attr( \Navidban\Admin\Admin::SLUG ); ?>-tools" />
		<p>شناسهٔ محصول را وارد کنید. فقط خوانده می‌شود؛ هیچ چیزی تغییر نمی‌کند.</p>
		<input dir="ltr" type="number" min="1" name="diag_product" value="<?php echo esc_attr( isset( $_GET['diag_product'] ) ? (int) $_GET['diag_product'] : '' ); ?>" placeholder="123" />
		<button class="button">بررسی</button>
	</form>

	<?php
	// Read-only diagnostic. The question it answers is the most common support
	// ticket: "I restocked and nothing was sent". Every row below is a fact from
	// the database, not a guess.
	$nvb_diag_id = isset( $_GET['diag_product'] ) ? (int) $_GET['diag_product'] : 0;

	if ( $nvb_diag_id > 0 ) :
		$nvb_diag_confirmed = (int) \Navidban\WaitlistRepository::count_confirmed( $nvb_diag_id, 0 );
		$nvb_diag_snapshot  = \Navidban\WaitlistRepository::snapshot_get( $nvb_diag_id, 0 );
		$nvb_diag_rows      = \Navidban\WaitlistRepository::query(
			array(
				'product_id' => $nvb_diag_id,
				'per_page'   => 500,
				'page'       => 1,
				'orderby'    => 'created_at',
				'order'      => 'ASC',
			)
		);

		$nvb_diag_status = array();
		foreach ( (array) $nvb_diag_rows['rows'] as $nvb_diag_row ) {
			$nvb_diag_key                  = (string) $nvb_diag_row['status'];
			$nvb_diag_status[ $nvb_diag_key ] = (int) ( $nvb_diag_status[ $nvb_diag_key ] ?? 0 ) + 1;
		}

		$nvb_diag_queue = array();
		if ( class_exists( '\\Navidban\\Queue' ) ) {
			$nvb_diag_queue = \Navidban\Queue::stats();
		}
		?>
		<div class="nvb-card">
			<h3>نتیجه برای محصول <code dir="ltr"><?php echo esc_html( (string) $nvb_diag_id ); ?></code></h3>
			<table class="widefat striped nvb-table">
				<tbody>
					<tr>
						<th scope="row">کل درخواست‌ها</th>
						<td><?php echo esc_html( (string) (int) $nvb_diag_rows['total'] ); ?></td>
					</tr>
					<tr>
						<th scope="row">تأییدشده (واجد شرایط اعلان)</th>
						<td>
							<strong><?php echo esc_html( (string) $nvb_diag_confirmed ); ?></strong>
							<?php if ( 0 === $nvb_diag_confirmed ) : ?>
								— <em>علت اصلی همین است: با تأیید دومرحله‌ای، فقط ردیف «تأییدشده» اعلان می‌گیرد.</em>
							<?php endif; ?>
						</td>
					</tr>
					<?php foreach ( $nvb_diag_status as $nvb_diag_label => $nvb_diag_count ) : ?>
						<tr>
							<th scope="row">وضعیت <?php echo esc_html( $nvb_diag_label ); ?></th>
							<td><?php echo esc_html( (string) $nvb_diag_count ); ?></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row">تصویر موجودی (snapshot)</th>
						<td>
							<?php if ( is_array( $nvb_diag_snapshot ) ) : ?>
								<code dir="ltr"><?php echo esc_html( (string) $nvb_diag_snapshot['stock_status'] ); ?></code>
								· موجودی ثبت‌شده: <?php echo esc_html( (string) (int) $nvb_diag_snapshot['stock_qty'] ); ?>
								· آخرین اعلان برای موجودی: <?php echo esc_html( (string) (int) $nvb_diag_snapshot['announced_qty'] ); ?>
								· بازبینی: <?php echo esc_html( (string) $nvb_diag_snapshot['checked_at'] ); ?>
							<?php else : ?>
								<em>هیچ ردیفی نیست — یعنی این محصول هرگز از نظر افزونه بررسی نشده است.</em>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">صف ارسال (کل سایت)</th>
						<td>
							<?php
							$nvb_diag_parts = array();
							foreach ( (array) $nvb_diag_queue as $nvb_q_status => $nvb_q_count ) {
								if ( is_numeric( $nvb_q_count ) ) {
									$nvb_diag_parts[] = $nvb_q_status . '=' . (int) $nvb_q_count;
								}
							}
							echo esc_html( implode( ' · ', $nvb_diag_parts ) );
							?>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="description">
				اگر «تأییدشده» صفر است: مشتری پیوند تأیید را باز نکرده (یا ایمیل/پیامک نرسیده).
				می‌توانید از صفحهٔ «لیست انتظارها» ردیف را دستی تأیید کنید.
				اگر «تأییدشده» بالای صفر است ولی صف خالی است: یا گذار موجودی تشخیص داده نشده
				(ابزار «اجرای پایش موجودی» را بزنید) یا موجودی جدید از آخرین اعلان بیشتر نبوده است.
			</p>
		</div>
	<?php endif; ?>

>>>>>>> 4ed7736 (۱.۰.۳: رفع هشدار کاذب sendmail + پنل «چرا پیامی نرفت؟»)
	<h2>وضعیت جدول‌ها</h2>
	<table class="widefat striped nvb-table">
		<thead><tr><th>جدول</th><th>موتور</th></tr></thead>
		<tbody>
			<?php foreach ( $tables as $name => $engine ) : ?>
				<tr>
					<td><?php echo esc_html( $name ); ?></td>
					<td><?php echo esc_html( 'InnoDB' === $engine ? 'InnoDB (درست)' : ( 'missing' === $engine ? 'ساخته نشده' : $engine ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="nvb-footer">نویدبان — محصولی از اتحاد وردپرس</p>
</div>
