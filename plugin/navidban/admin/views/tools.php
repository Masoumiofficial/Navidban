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
