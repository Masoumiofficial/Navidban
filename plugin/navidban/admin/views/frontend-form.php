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
 * @var string $notice One-shot notice code.
 *
 * @package Navidban
 */

defined( 'ABSPATH' ) || exit;

$nvb_notice_text = array(
	'confirmed'    => 'اشتراک شما تأیید شد. به‌محض بازگشت موجودی خبرتان می‌کنیم.',
	'already'      => 'اشتراک شما از قبل تأیید شده بود.',
	'expired'      => 'پیوند تأیید منقضی شده است. لطفاً دوباره ثبت کنید.',
	'invalid'      => 'این پیوند معتبر نیست.',
	'unsubscribed' => 'اشتراک شما لغو شد. دیگر پیامی دریافت نمی‌کنید.',
);

$nvb_mode = isset( $settings['mode'] ) ? $settings['mode'] : 'email';
?>
<section
	class="nvb-form"
	id="nvb-form"
	dir="rtl"
	data-product="<?php echo esc_attr( (string) $product_id ); ?>"
	data-mode="<?php echo esc_attr( $nvb_mode ); ?>"
	data-consent="<?php echo ! empty( $settings['consent_req'] ) ? '1' : '0'; ?>"
	data-variations="<?php echo esc_attr( wp_json_encode( $variations ) ); ?>"
>
	<h3 class="nvb-form__title"><?php echo esc_html( (string) $settings['heading'] ); ?></h3>

	<?php if ( '' !== (string) $settings['notice'] && isset( $nvb_notice_text[ $settings['notice'] ] ) ) : ?>
		<p class="nvb-form__notice" role="status">
			<?php echo esc_html( $nvb_notice_text[ $settings['notice'] ] ); ?>
		</p>
	<?php endif; ?>

	<p class="nvb-form__intro"><?php echo esc_html( (string) $settings['intro'] ); ?></p>

	<form class="nvb-form__body" novalidate>
		<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product_id ); ?>" />
		<input type="hidden" name="variation_id" value="0" data-nvb="variation-id" />

		<?php if ( 'both' === $nvb_mode ) : ?>
			<fieldset class="nvb-form__channels">
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
			<input type="hidden" name="channel" value="<?php echo esc_attr( 'sms' === $nvb_mode ? 'sms' : 'email' ); ?>" />
		<?php endif; ?>

		<div class="nvb-form__fields">
			<?php if ( 'sms' !== $nvb_mode ) : ?>
				<p class="nvb-form__field" data-nvb="email-field">
					<label for="nvb-email">ایمیل</label>
					<input
						type="email"
						id="nvb-email"
						name="email"
						dir="ltr"
						inputmode="email"
						autocomplete="email"
						value="<?php echo esc_attr( $prefill_email ); ?>"
						<?php echo 'both' === $nvb_mode ? '' : 'required'; ?>
					/>
				</p>
			<?php endif; ?>

			<?php if ( 'email' !== $nvb_mode ) : ?>
				<p class="nvb-form__field" data-nvb="phone-field">
					<label for="nvb-phone">شمارهٔ موبایل</label>
					<input
						type="tel"
						id="nvb-phone"
						name="phone"
						dir="ltr"
						inputmode="tel"
						autocomplete="tel"
						placeholder="09xxxxxxxxx"
						value="<?php echo esc_attr( $prefill_phone ); ?>"
						<?php echo 'both' === $nvb_mode ? '' : 'required'; ?>
					/>
				</p>
			<?php endif; ?>
		</div>

		<div class="nvb-form__hp" aria-hidden="true">
			<label for="nvb-website">این فیلد را خالی بگذارید</label>
			<input type="text" id="nvb-website" name="website" tabindex="-1" autocomplete="off" />
		</div>

		<?php if ( ! empty( $settings['consent_req'] ) ) : ?>
			<p class="nvb-form__consent">
				<label>
					<input type="checkbox" name="consent" value="1" />
					<?php echo esc_html( (string) $settings['consent'] ); ?>
				</label>
				<?php if ( '' !== (string) $settings['privacy'] ) : ?>
					<a href="<?php echo esc_url( (string) $settings['privacy'] ); ?>" target="_blank" rel="noopener noreferrer">سیاست حریم خصوصی</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<p class="nvb-form__actions">
			<button type="submit" class="nvb-button"><?php echo esc_html( (string) $settings['button'] ); ?></button>
		</p>

		<p class="nvb-form__message" role="status" aria-live="polite" data-nvb="message"></p>
	</form>

	<noscript>
		<p class="nvb-form__intro">
			برای ثبت درخواست، جاوااسکریپت مرورگر باید فعال باشد. می‌توانید با پشتیبانی فروشگاه تماس بگیرید.
		</p>
	</noscript>
</section>
