<?php
/**
 * Phone follow-up panel.
 *
 * One component, two hosts: inside the row dialog on the waitlist screen, and
 * on the standalone page that a browser without JavaScript lands on. Keeping a
 * single partial is what guarantees the no-JS path is not a forgotten second
 * implementation.
 *
 * Expects from the caller:
 *  - $row     (array)  one waitlist row, must contain id, phone, email, full_name;
 *  - $history (array)  CallLog::history() rows, newest first;
 *  - $return  (string) query string to come back to, already sanitised;
 *  - $variant (string) 'modal' | 'page'.
 *
 * @package Mojoodyar
 */

defined( 'ABSPATH' ) || exit;

use Mojoodyar\CallLog;
use Mojoodyar\Support\Jalali;

$mjy_variant = isset( $variant ) ? (string) $variant : 'modal';
$mjy_history = isset( $history ) && is_array( $history ) ? $history : array();
$mjy_return  = isset( $return ) ? (string) $return : '';
$mjy_row     = isset( $row ) && is_array( $row ) ? $row : array();

$mjy_id      = isset( $mjy_row['id'] ) ? (int) $mjy_row['id'] : 0;
$mjy_name    = isset( $mjy_row['full_name'] ) ? trim( (string) $mjy_row['full_name'] ) : '';
$mjy_phone   = isset( $mjy_row['phone'] ) ? (string) $mjy_row['phone'] : '';
$mjy_closed  = isset( $mjy_row['call_state'] ) && 'closed' === $mjy_row['call_state'];
$mjy_attempt = count( $mjy_history );
$mjy_next    = $mjy_attempt + 1;
$mjy_reason  = isset( $mjy_row['call_close_reason'] ) ? (string) $mjy_row['call_close_reason'] : '';
?>
<div class="mjy-follow" data-mjy="follow" data-stage="<?php echo esc_attr( (string) $mjy_next ); ?>">

	<div class="mjy-follow__who">
		<div>
			<strong class="mjy-follow__name">
				<?php echo '' !== $mjy_name ? esc_html( $mjy_name ) : 'بدون نام' ; ?>
			</strong>
			<?php if ( '' !== $mjy_phone ) : ?>
				<a class="mjy-follow__phone" dir="ltr" href="<?php echo esc_url( 'tel:' . $mjy_phone ); ?>">
					<?php echo esc_html( $mjy_phone ); ?>
				</a>
			<?php endif; ?>
		</div>
		<span class="mjy-follow__badge <?php echo $mjy_closed ? 'is-closed' : 'is-open'; ?>">
			<?php
			if ( $mjy_closed ) {
				echo esc_html( 'بسته‌شده — ' . CallLog::reason_label( $mjy_reason ) );
			} else {
				echo esc_html( 0 === $mjy_attempt ? 'در انتظار اولین تماس' : 'در جریان' );
			}
			?>
		</span>
	</div>

	<?php if ( $mjy_closed && ! empty( $mjy_row['call_closed_at'] ) ) : ?>
		<p class="mjy-follow__closed-note">
			پیگیری در <?php echo esc_html( Jalali::format( (string) $mjy_row['call_closed_at'], 'j F Y — H:i' ) ); ?> بسته شد.
			<?php if ( ! empty( $mjy_row['call_close_note'] ) ) : ?>
				<br /><span class="mjy-muted"><?php echo esc_html( (string) $mjy_row['call_close_note'] ); ?></span>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<h3 class="mjy-follow__title">تاریخچهٔ تماس‌ها</h3>

	<?php if ( empty( $mjy_history ) ) : ?>
		<p class="mjy-follow__empty">هنوز تماسی برای این مشتری ثبت نشده است. نتیجهٔ تماس اول را انتخاب کنید.</p>
	<?php else : ?>
		<ol class="mjy-follow__timeline">
			<?php foreach ( $mjy_history as $mjy_entry ) : ?>
				<?php
				$mjy_entry_stage = isset( $mjy_entry['stage'] ) ? (int) $mjy_entry['stage'] : 0;
				$mjy_entry_out   = isset( $mjy_entry['outcome'] ) ? (string) $mjy_entry['outcome'] : '';
				$mjy_entry_note  = isset( $mjy_entry['note'] ) ? (string) $mjy_entry['note'] : '';
				?>
				<li class="mjy-follow__item">
					<span class="mjy-follow__stage"><?php echo esc_html( CallLog::stage_label( $mjy_entry_stage ) ); ?></span>
					<div class="mjy-follow__body">
						<strong><?php echo esc_html( CallLog::outcome_label( $mjy_entry_out ) ); ?></strong>
						<?php if ( '' !== $mjy_entry_note ) : ?>
							<p class="mjy-follow__note"><?php echo esc_html( $mjy_entry_note ); ?></p>
						<?php endif; ?>
						<small class="mjy-muted">
							<?php echo esc_html( Jalali::format( (string) ( $mjy_entry['created_at'] ?? '' ), 'j F Y — H:i' ) ); ?>
						</small>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mjy-follow__form" data-mjy="follow-form">
		<?php wp_nonce_field( 'mojoodyar_call' ); ?>
		<input type="hidden" name="action" value="mojoodyar_call" />
		<input type="hidden" name="id" value="<?php echo esc_attr( (string) $mjy_id ); ?>" />
		<input type="hidden" name="return" value="<?php echo esc_attr( $mjy_return ); ?>" />

		<?php if ( ! $mjy_closed ) : ?>
			<fieldset class="mjy-follow__outcomes">
				<legend>نتیجهٔ <?php echo esc_html( CallLog::stage_label( $mjy_next ) ); ?></legend>
				<?php foreach ( CallLog::OUTCOMES as $mjy_key => $mjy_label ) : ?>
					<label class="mjy-outcome">
						<input type="radio" name="outcome" value="<?php echo esc_attr( $mjy_key ); ?>" required />
						<span><?php echo esc_html( $mjy_label ); ?></span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<p class="mjy-follow__field">
				<label for="mjy-note-<?php echo esc_attr( (string) $mjy_id ); ?>">توضیح این تماس (اختیاری)</label>
				<textarea
					id="mjy-note-<?php echo esc_attr( (string) $mjy_id ); ?>"
					name="note"
					rows="3"
					maxlength="500"
					placeholder="مثلاً: گفت تا آخر هفته تصمیم می‌گیرد"
					data-mjy="note"
				></textarea>
				<span class="mjy-follow__counter" data-mjy="counter">۰ / ۵۰۰</span>
			</p>

			<div class="mjy-follow__actions">
				<button type="submit" name="call_action" value="attempt" class="mjy-btn mjy-btn--primary" data-mjy="follow-save">
					ثبت <?php echo esc_html( CallLog::stage_label( $mjy_next ) ); ?>
				</button>
				<button type="submit" name="call_action" value="close" class="mjy-btn mjy-btn--danger" data-mjy="follow-close" formnovalidate>
					خاتمهٔ پیگیری
				</button>
			</div>

			<p class="mjy-follow__field mjy-follow__reason">
				<label for="mjy-reason-<?php echo esc_attr( (string) $mjy_id ); ?>">دلیل خاتمه (برای دکمهٔ «خاتمهٔ پیگیری»)</label>
				<select id="mjy-reason-<?php echo esc_attr( (string) $mjy_id ); ?>" name="close_reason">
					<option value="">— انتخاب کنید —</option>
					<?php foreach ( CallLog::CLOSE_REASONS as $mjy_key => $mjy_label ) : ?>
						<option value="<?php echo esc_attr( $mjy_key ); ?>"><?php echo esc_html( $mjy_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="mjy-follow__hint">
				نتیجه‌های «سفارش داد»، «شماره اشتباه بود» و «درخواست لغو کرد» پیگیری را خودکار می‌بندند.
			</p>
		<?php else : ?>
			<input type="hidden" name="call_action" value="reopen" />
			<div class="mjy-follow__actions">
				<button type="submit" class="mjy-btn mjy-btn--primary">بازکردن دوبارهٔ پیگیری</button>
			</div>
			<p class="mjy-follow__hint">با بازکردن، تاریخچهٔ تماس‌ها پاک نمی‌شود و مشتری به لیست تماس برمی‌گردد.</p>
		<?php endif; ?>
	</form>

	<?php if ( 'modal' === $mjy_variant ) : ?>
		<p class="mjy-follow__fallback">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=mojoodyar-followup&id=' . $mjy_id ) ); ?>">
				بازکردن این پیگیری در یک صفحهٔ جدا
			</a>
		</p>
	<?php endif; ?>
</div>
