<?php
/**
 * Waitlist table view.
 *
 * @var array $rows     Rows for the current page.
 * @var int   $total    Total matching rows.
 * @var array $args     Active filters.
 * @var int   $per_page Page size.
 *
 * @package Mojoodyar
 */

defined( 'ABSPATH' ) || exit;

use Mojoodyar\Notifier;
use Mojoodyar\Support\Jalali;
use Mojoodyar\Support\Masking;
use Mojoodyar\Support\Persian;
use Mojoodyar\WaitlistRepository;

$mjy_pages = max( 1, (int) ceil( $total / max( 1, $per_page ) ) );

// One query for the whole page instead of two per row.
$mjy_row_ids   = array();
$mjy_histories = array();

foreach ( (array) $rows as $mjy_row_for_ids ) {
	$mjy_row_ids[] = (int) $mjy_row_for_ids['id'];
}

$mjy_call_counts = \Mojoodyar\CallLog::summaries( $mjy_row_ids );
$mjy_export = wp_nonce_url(
	add_query_arg(
		array_merge(
			array( 'action' => 'mojoodyar_export', 'paged' => $args['page'] ),
			array_filter(
				array(
					'product_id' => $args['product_id'] ?: '',
					'status'     => $args['status'],
					'channel'    => $args['channel'],
					'called'     => $args['called'],
					'from'       => $args['from'],
					'to'         => $args['to'],
					's'          => $args['search'],
				)
			)
		),
		admin_url( 'admin-post.php' )
	),
	'mojoodyar_export'
);

$mjy_statuses = array(
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
	$notice = get_transient( 'mojoodyar_tool_notice' );
	if ( $notice ) :
		delete_transient( 'mojoodyar_tool_notice' );
		?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( (string) $notice ); ?></p></div>
	<?php endif; ?>

	<form method="get" class="mjy-filters">
		<input type="hidden" name="page" value="mojoodyar-waitlists" />

		<label>شناسهٔ محصول
			<input type="number" name="product_id" value="<?php echo esc_attr( $args['product_id'] ? (string) $args['product_id'] : '' ); ?>" />
		</label>

		<label>وضعیت
			<select name="status">
				<?php foreach ( $mjy_statuses as $value => $label ) : ?>
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

		<label>تماس
			<select name="called">
				<option value="" <?php selected( $args['called'], '' ); ?>>همه</option>
				<option value="no" <?php selected( $args['called'], 'no' ); ?>>تماس گرفته نشده</option>
				<option value="yes" <?php selected( $args['called'], 'yes' ); ?>>تماس گرفته شده</option>
			</select>
		</label>

		<label>از تاریخ (میلادی)
			<input type="date" name="from" value="<?php echo esc_attr( $args['from'] ); ?>" />
		</label>

		<label>تا تاریخ (میلادی)
			<input type="date" name="to" value="<?php echo esc_attr( $args['to'] ); ?>" />
		</label>

		<label>جست‌وجو
			<input type="search" name="s" value="<?php echo esc_attr( $args['search'] ); ?>" placeholder="نام، ایمیل یا موبایل" />
		</label>

		<button class="button">اعمال فیلتر</button>
		<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=mojoodyar-waitlists' ) ); ?>">پاک کردن</a>
		<a class="button button-primary" href="<?php echo esc_url( $mjy_export ); ?>">خروجی CSV</a>
	</form>

	<p class="mjy-muted">
		<?php echo esc_html( Persian::number( $total ) ); ?> ردیف — صفحهٔ
		<?php echo esc_html( Persian::number( $args['page'] ) ); ?> از <?php echo esc_html( Persian::number( $mjy_pages ) ); ?>
		· ایمیل‌ها و موبایل‌ها در این جدول پوشانده می‌شوند؛ فقط خروجی CSV کامل است.
	</p>

	<?php
	/*
	 * The bulk controls live in their own form and the row checkboxes point at it
	 * with the HTML5 `form` attribute. Reason: every row now contains its own
	 * "called" form, and a <form> inside a <form> is invalid HTML - browsers drop
	 * the inner one, which would silently break the call tick.
	 */
	?>
	<form id="mjy-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'mojoodyar_bulk' ); ?>
		<input type="hidden" name="action" value="mojoodyar_bulk" />

		<div class="mjy-bulk">
			<select name="bulk_action">
				<option value="">عملیات گروهی…</option>
				<option value="confirm">تأیید دستی</option>
				<option value="called">علامت‌گذاری: تماس گرفته شد</option>
				<option value="uncalled">برداشتن تیک تماس</option>
				<option value="unsubscribe">لغو اشتراک</option>
				<option value="delete">حذف</option>
			</select>
			<button class="button">اجرا</button>
		</div>
	</form>

	<table class="widefat striped mjy-table">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" id="mjy-select-all" /></td>
					<th>#</th>
					<th>مشتری</th>
					<th>محصول</th>
					<th>کانال</th>
					<th>مخاطب</th>
					<th>وضعیت</th>
					<th>تماس</th>
					<th>ثبت</th>
					<th>تأیید</th>
					<th>اعلان</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="11">هیچ ردیفی با این فیلترها پیدا نشد.</td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<th scope="row" class="check-column">
							<input type="checkbox" name="ids[]" form="mjy-bulk-form" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
						</th>
						<td><?php echo esc_html( Persian::number( $row['id'] ) ); ?></td>
						<td>
							<?php if ( '' !== (string) ( $row['full_name'] ?? '' ) ) : ?>
								<?php echo esc_html( (string) $row['full_name'] ); ?>
							<?php else : ?>
								<span class="mjy-muted">— بی‌نام</span>
							<?php endif; ?>
						</td>
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
						<td><?php echo esc_html( $mjy_statuses[ $row['status'] ] ?? (string) $row['status'] ); ?></td>
						<td><?php echo esc_html( Jalali::format( (string) $row['created_at'], 'j F Y' ) ); ?></td>
						<td>
							<?php
							$mjy_called = ! empty( $row['called_at'] );
							$return = http_build_query(
								array_filter(
									array(
										'product_id' => $args['product_id'] ?: '',
										'status'     => $args['status'],
										'channel'    => $args['channel'],
										'called'     => $args['called'],
										'from'       => $args['from'],
										'to'         => $args['to'],
										's'          => $args['search'],
										'paged'      => $args['page'] > 1 ? $args['page'] : '',
									)
								)
							);
							?>
							<?php
							$mjy_attempts = isset( $mjy_call_counts[ (int) $row['id'] ] ) ? (int) $mjy_call_counts[ (int) $row['id'] ] : 0;
							$mjy_closed   = isset( $row['call_state'] ) && 'closed' === $row['call_state'];
							?>
							<?php /* The link works on its own (no JavaScript, new tab, middle click); the script upgrades it to the dialog. */ ?>
							<a
								class="mjy-call__open <?php echo $mjy_closed ? 'is-closed' : ''; ?>"
								href="<?php echo esc_url( admin_url( 'admin.php?page=mojoodyar-followup&id=' . (int) $row['id'] ) ); ?>"
								data-mjy="call-open"
								data-target="mjy-call-<?php echo esc_attr( (string) $row['id'] ); ?>"
							>
								پیگیری تلفنی
							</a>
							<?php if ( $mjy_closed ) : ?>
								<small class="mjy-call__when">بسته — <?php echo esc_html( \Mojoodyar\CallLog::reason_label( (string) $row['call_close_reason'] ) ); ?></small>
							<?php elseif ( $mjy_attempts > 0 ) : ?>
								<small class="mjy-call__when">
									<?php echo esc_html( \Mojoodyar\CallLog::stage_label( $mjy_attempts ) . ' — ' . Jalali::format( (string) $row['called_at'], 'j F' ) ); ?>
								</small>
							<?php endif; ?>
						</td>
						<td><?php echo $row['confirmed_at'] ? esc_html( Jalali::format( (string) $row['confirmed_at'], 'j F Y' ) ) : '—'; ?></td>
						<td><?php echo $row['notified_at'] ? esc_html( Jalali::format( (string) $row['notified_at'], 'j F Y' ) ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
	</table>

	<?php if ( ! empty( $rows ) ) : ?>
		<?php foreach ( $rows as $row ) : ?>
			<?php
			$history = \Mojoodyar\CallLog::history( (int) $row['id'] );
			$variant = 'modal';
			?>
			<dialog class="mjy-modal" id="mjy-call-<?php echo esc_attr( (string) $row['id'] ); ?>" data-mjy="call-modal" aria-labelledby="mjy-call-title-<?php echo esc_attr( (string) $row['id'] ); ?>">
				<div class="mjy-modal__panel">
					<header class="mjy-modal__head">
						<h2 id="mjy-call-title-<?php echo esc_attr( (string) $row['id'] ); ?>" class="mjy-modal__title">
							پیگیری تلفنی — <?php echo esc_html( '' !== (string) ( $row['full_name'] ?? '' ) ? (string) $row['full_name'] : 'مشتری بی‌نام' ); ?>
						</h2>
						<button type="button" class="mjy-modal__x" data-mjy="call-close" aria-label="بستن">&times;</button>
					</header>
					<div class="mjy-modal__body">
						<?php
						$return = http_build_query(
							array_filter(
								array(
									'product_id' => $args['product_id'] ?: '',
									'status'     => $args['status'],
									'channel'    => $args['channel'],
									'called'     => $args['called'],
									'from'       => $args['from'],
									'to'         => $args['to'],
									's'          => $args['search'],
									'paged'      => $args['page'] > 1 ? $args['page'] : '',
								)
							)
						);
						?>
						<?php include MOJODYAR_DIR . 'admin/views/call-panel.php'; ?>
					</div>
				</div>
			</dialog>
		<?php endforeach; ?>
	<?php endif; ?>

	<?php if ( $mjy_pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => (int) $args['page'],
					'total'     => $mjy_pages,
					'prev_text' => 'قبلی',
					'next_text' => 'بعدی',
				)
			);
			?>
		</div></div>
	<?php endif; ?>
	<?php $this->close_screen(); ?>
