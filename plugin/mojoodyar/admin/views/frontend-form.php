<?php
/**
 * Storefront waitlist form.
 *
 * Escaped output only; no inline event handlers (the JS file binds behaviour).
 *
 * @var array  $settings Form settings.
 * @var int    $product_id
 * @var array  $variations Variation list with stock state.
 * @var string $default_channel Default channel in "both" mode.
 * @var int    $user_id
 * @var string $prefill_email
 * @var string $prefill_phone
 * @var string $prefill_name
 * @var string $notice One-shot notice code.
 *
 * @package Mojoodyar
 */

defined( 'ABSPATH' ) || exit;

$mjy_notice_text = array(
	'confirmed'    => 'اشتراک شما تأیید شد. به‌محض بازگشت موجودی خبرتان می‌کنیم.',
	'already'      => 'اشتراک شما از قبل تأیید شده بود.',
	'expired'      => 'پیوند تأیید منقضی شده است. لطفاً دوباره ثبت کنید.',
	'invalid'      => 'این پیوند معتبر نیست.',
	'unsubscribed' => 'اشتراک شما لغو شد. دیگر پیامی دریافت نمی‌کنید.',
);

$mjy_mode = isset( $settings['mode'] ) ? $settings['mode'] : 'email';
?>
<section
	class="mjy-form"
	id="mjy-form"
	dir="rtl"
	data-product="<?php echo esc_attr( (string) $product_id ); ?>"
	data-mode="<?php echo esc_attr( $mjy_mode ); ?>"
	data-consent="<?php echo ! empty( $settings['consent_req'] ) ? '1' : '0'; ?>"
	data-variations="<?php echo esc_attr( wp_json_encode( $variations ) ); ?>"
>
	<h3 class="mjy-form__title"><?php echo esc_html( (string) $settings['heading'] ); ?></h3>

	<?php if ( '' !== (string) $settings['notice'] && isset( $mjy_notice_text[ $settings['notice'] ] ) ) : ?>
		<p class="mjy-form__notice" role="status">
			<?php echo esc_html( $mjy_notice_text[ $settings['notice'] ] ); ?>
		</p>
	<?php endif; ?>

	<p class="mjy-form__intro"><?php echo esc_html( (string) $settings['intro'] ); ?></p>

	<form class="mjy-form__body" novalidate>
		<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product_id ); ?>" />
		<input type="hidden" name="variation_id" value="0" data-mjy="variation-id" />

		<?php if ( 'both' === $mjy_mode ) : ?>
			<fieldset class="mjy-form__channels">
				<legend>چگونه خبرتان کنیم؟</legend>
				<label>
					<input type="radio" name="channel" value="email" <?php checked( 'email', $default_channel ); ?> />
					ایمیل
				</label>
				<label>
					<input type="radio" name="channel" value="sms" <?php checked( 'sms', $default_channel ); ?> />
					پیامک
				</label>
			</fieldset>
		<?php else : ?>
			<input type="hidden" name="channel" value="<?php echo esc_attr( 'sms' === $mjy_mode ? 'sms' : 'email' ); ?>" />
		<?php endif; ?>

		<div class="mjy-form__fields">
			<?php if ( ! empty( $settings['name_field'] ) ) : ?>
				<p class="mjy-form__field" data-mjy="name-field">
					<label for="mjy-name">نام و نام خانوادگی</label>
					<input
						type="text"
						id="mjy-name"
						name="name"
						maxlength="120"
						autocomplete="name"
						placeholder="مثلاً مریم احمدی"
						value="<?php echo esc_attr( isset( $prefill_name ) ? $prefill_name : '' ); ?>"
						<?php echo ! empty( $settings['name_req'] ) ? 'required' : ''; ?>
					/>
				</p>
			<?php endif; ?>

			<?php if ( 'sms' !== $mjy_mode ) : ?>
				<p class="mjy-form__field" data-mjy="email-field">
					<label for="mjy-email">ایمیل</label>
					<input
						type="email"
						id="mjy-email"
						name="email"
						dir="ltr"
						inputmode="email"
						autocomplete="email"
						value="<?php echo esc_attr( $prefill_email ); ?>"
						<?php echo 'both' === $mjy_mode ? '' : 'required'; ?>
					/>
				</p>
			<?php endif; ?>

			<?php if ( 'email' !== $mjy_mode ) : ?>
				<p class="mjy-form__field" data-mjy="phone-field">
					<label for="mjy-phone">شمارهٔ موبایل</label>
					<input
						type="tel"
						id="mjy-phone"
						name="phone"
						dir="ltr"
						inputmode="tel"
						autocomplete="tel"
						placeholder="09xxxxxxxxx"
						value="<?php echo esc_attr( $prefill_phone ); ?>"
						<?php echo 'both' === $mjy_mode ? '' : 'required'; ?>
					/>
				</p>
			<?php endif; ?>
		</div>

		<div class="mjy-form__hp" aria-hidden="true">
			<label for="mjy-website">این فیلد را خالی بگذارید</label>
			<input type="text" id="mjy-website" name="website" tabindex="-1" autocomplete="off" />
		</div>

		<?php if ( ! empty( $settings['consent_req'] ) ) : ?>
			<p class="mjy-form__consent">
				<label>
					<input type="checkbox" name="consent" value="1" />
					<?php echo esc_html( (string) $settings['consent'] ); ?>
				</label>
				<?php if ( '' !== (string) $settings['privacy'] ) : ?>
					<a href="<?php echo esc_url( (string) $settings['privacy'] ); ?>" target="_blank" rel="noopener noreferrer">سیاست حریم خصوصی</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<p class="mjy-form__actions">
			<button type="submit" class="mjy-button"><?php echo esc_html( (string) $settings['button'] ); ?></button>
		</p>

		<p class="mjy-form__message" role="status" aria-live="polite" data-mjy="message"></p>
	</form>

	<noscript>
		<p class="mjy-form__intro">
			برای ثبت درخواست، جاوااسکریپت مرورگر باید فعال باشد. می‌توانید با پشتیبانی فروشگاه تماس بگیرید.
		</p>
	</noscript>
</section>
