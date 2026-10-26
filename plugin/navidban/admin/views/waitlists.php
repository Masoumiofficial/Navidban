<?php
/**
 * Waitlist table view.
 *
 * @var array $rows     Rows for the current page.
 * @var int   $total    Total matching rows.
 * @var array $args     Active filters.
 * @var int   $per_page Page size.
 *
 * @package Navidban
 */

defined( 'ABSPATH' ) || exit;

use Navidban\Notifier;
use Navidban\Support\Jalali;
use Navidban\Support\Masking;
use Navidban\Support\Persian;
use Navidban\WaitlistRepository;

$nvb_pages = max( 1, (int) ceil( $total / max( 1, $per_page ) ) );
$nvb_export = wp_nonce_url(
	add_query_arg(
		array_merge(
			array( 'action' => 'navidban_export', 'paged' => $args['page'] ),
			array_filter(
				array(
					'product_id' => $args['product_id'] ?: '',
					'status'     => $args['status'],
					'channel'    => $args['channel'],
					'from'       => $args['from'],
					'to'         => $args['to'],
					's'          => $args['search'],
				)
			)
		),
		admin_url( 'admin-post.php' )
	),
	'navidban_export'
);

$nvb_statuses = array(
	''             => 'همهٔ وضعیت‌ها',
	'pending'      => 'در انتظار تأیید',
	'confirmed'    => 'تأییدشده',
	'notified'     => 'اعلان‌شده',
	'expired'      => 'منقضی',
	'unsubscribed' => 'لغو شده',
);
?>
<?php $this->open_screen( 'waitlists', 'لیست انتظارها', 'همهٔ درخواست‌ها، با فیلتر و خروجی CSV' ); ?>

	<?php
	$notice = get_transient( 'navidban_tool_notice' );
	if ( $notice ) :
		delete_transient( 'navidban_tool_notice' );
		?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( (string) $notice ); ?></p></div>
	<?php endif; ?>

	<form method="get" class="nvb-filters">
		<input type="hidden" name="page" value="navidban-waitlists" />

		<label>شناسهٔ محصول
			<input type="number" name="product_id" value="<?php echo esc_attr( $args['product_id'] ? (string) $args['product_id'] : '' ); ?>" />
		</label>

		<label>وضعیت
			<select name="status">
				<?php foreach ( $nvb_statuses as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $args['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>

		<label>کانال
			<select name="channel">
				<option value="">همه</option>
				<option value="email" <?php selected( $args['channel'], 'email' ); ?>>ایمیل</option>
				<option value="sms" <?php selected( $args['channel'], 'sms' ); ?>>پیامک</option>
			</select>
		</label>

		<label>از تاریخ (میلادی)
			<input type="date" name="from" value="<?php echo esc_attr( $args['from'] ); ?>" />
		</label>

		<label>تا تاریخ (میلادی)
			<input type="date" name="to" value="<?php echo esc_attr( $args['to'] ); ?>" />
		</label>

		<label>جست‌وجو
			<input type="search" name="s" value="<?php echo esc_attr( $args['search'] ); ?>" placeholder="ایمیل یا موبایل" />
		</label>

		<button class="button">اعمال فیلتر</button>
		<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=navidban-waitlists' ) ); ?>">پاک کردن</a>
		<a class="button button-primary" href="<?php echo esc_url( $nvb_export ); ?>">خروجی CSV</a>
	</form>

	<p class="nvb-muted">
		<?php echo esc_html( Persian::number( $total ) ); ?> ردیف — صفحهٔ
		<?php echo esc_html( Persian::number( $args['page'] ) ); ?> از <?php echo esc_html( Persian::number( $nvb_pages ) ); ?>
		· ایمیل‌ها و موبایل‌ها در این جدول پوشانده می‌شوند؛ فقط خروجی CSV کامل است.
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'navidban_bulk' ); ?>
		<input type="hidden" name="action" value="navidban_bulk" />

		<div class="nvb-bulk">
			<select name="bulk_action">
				<option value="">عملیات گروهی…</option>
				<option value="confirm">تأیید دستی</option>
				<option value="unsubscribe">لغو اشتراک</option>
				<option value="delete">حذف</option>
			</select>
			<button class="button">اجرا</button>
		</div>

		<table class="widefat striped nvb-table">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" id="nvb-select-all" /></td>
					<th>#</th>
					<th>محصول</th>
					<th>کانال</th>
					<th>مخاطب</th>
					<th>وضعیت</th>
					<th>ثبت</th>
					<th>تأیید</th>
					<th>اعلان</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="9">هیچ ردیفی با این فیلترها پیدا نشد.</td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<th scope="row" class="check-column">
							<input type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
						</th>
						<td><?php echo esc_html( Persian::number( $row['id'] ) ); ?></td>
						<td>
							<?php echo esc_html( Notifier::product_name( (int) $row['product_id'], (int) $row['variation_id'] ) ); ?>
							<?php if ( (int) $row['variation_id'] > 0 ) : ?>
								<br /><small>تنوع #<?php echo esc_html( Persian::number( $row['variation_id'] ) ); ?></small>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( 'sms' === $row['channel'] ? 'پیامک' : 'ایمیل' ); ?></td>
						<td>
							<?php
							if ( 'sms' === $row['channel'] ) {
								echo esc_html( Masking::phone( (string) $row['phone'] ) );
							} else {
								echo esc_html( Masking::email( (string) $row['email'] ) );
							}
							?>
						</td>
						<td><?php echo esc_html( $nvb_statuses[ $row['status'] ] ?? (string) $row['status'] ); ?></td>
						<td><?php echo esc_html( Jalali::format( (string) $row['created_at'], 'j F Y' ) ); ?></td>
						<td><?php echo $row['confirmed_at'] ? esc_html( Jalali::format( (string) $row['confirmed_at'], 'j F Y' ) ) : '—'; ?></td>
						<td><?php echo $row['notified_at'] ? esc_html( Jalali::format( (string) $row['notified_at'], 'j F Y' ) ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</form>

	<?php if ( $nvb_pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => (int) $args['page'],
					'total'     => $nvb_pages,
					'prev_text' => 'قبلی',
					'next_text' => 'بعدی',
				)
			);
			?>
		</div></div>
	<?php endif; ?>
	<?php $this->close_screen(); ?>
