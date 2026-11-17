<?php
/**
 * Storefront waitlist form.
 *
 * Escaped output only; no inline event handlers (the JS file binds behaviour).
 *
 * @var array  $settings Form settings.
 * @var int    $product_id Product ID.
 * @var array  $variations Selectable, unavailable variations.
 * @var string $form_id Unique ID for this instance.
 * @var bool   $is_variable Whether the product is variable.
 * @var string $placement automatic|shortcode.
 * @var bool   $sync_with_product Whether WooCommerce events may update this form.
 * @var string $default_channel Default channel in "both" mode.
 * @var int    $user_id Current user.
 * @var string $prefill_email Prefilled email.
 * @var string $prefill_phone Prefilled phone.
 * @var string $prefill_name Prefilled name.
 *
 * @package Mojoodyar
 */

defined( 'ABSPATH' ) || exit;

$mjy_notice_text = array(
	'confirmed'    => 'اشتراک شما تآیید شد. به‌محض بازگشت موجودی خبرتان می‌کنیم.',
	'already'      => 'اشتراک شما از قبل تآیید شده بود.',
	'expired'      => 'پیوند تآیید منقضی شده است. لطفاً دوباره ثبت کنید.',
	'invalid'      => 'این پیوند معتبر نیست.',
	'unsubscribed' => 'اشتراک شما لغو شد. دیگر پیامی دریافت نمی‌کنید.',
);

$mjy_mode          = isset( $settings['mode'] ) ? $settings['mode'] : 'email';
$form_id           = isset( $form_id ) ? (string) $form_id : 'mjy-form-preview';
$is_variable       = ! empty( $is_variable );
$placement         = isset( $placement ) ? (string) $placement : 'shortcode';
$sync_with_product = ! empty( $sync_with_product );
?>
<section
	class="mjy-form"
	id="<?php echo esc_attr( $form_id ); ?>"
	dir="rtl"
	data-product="<?php echo esc_attr( (string) $product_id ); ?>"
	data-mode="<?php echo esc_attr( $mjy_mode ); ?>"
	data-consent="<?php echo ! empty( $settings['consent_req'] ) ? '1' : '0'; ?>"
	data-variable="<?php echo $is_variable ? '1' : '0'; ?>"
	data-placement="<?php echo esc_attr( $placement ); ?>"
	data-sync-product="<?php echo $sync_with_product ? '1' : '0'; ?>"
>
	<h3 class="mjy-form__title"><?php echo esc_html( (string) $settings['heading'] ); ?></h3>

	<?php if ( '' !== (string) $settings['notice'] && isset( $mjy_notice_text[ $settings['notice'] ] ) ) : ?>
		<p class="mjy-form__notice" role="status">
			<?php echo esc_html( $mjy_notice_text[ $settings['notice'] ] ); ?>
		</p>
	<?php endif; ?>

	<p class="mjy-form__intro"><?php echo esc_html( (string) $settings['intro'] ); ?></p>

	<form class="mjy-form__body" method="post" novalidate>
		<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product_id ); ?>" />

		<?php if ( $is_variable ) : ?>
			<p class="mjy-form__field mjy-form__variation">
				<label for="<?php echo esc_attr( $form_id . '-variation' ); ?>">تنوع ناموجود</label>
				<select id="<?php echo esc_attr( $form_id . '-variation' ); ?>" name="variation_id" data-mjy="variation-id" required>
					<option value="0">تنوع مورد نظر را انتخاب کنید</option>
					<?php foreach ( $variations as $variation ) : ?>
						<option value="<?php echo esc_attr( (string) $variation['id'] ); ?>"><?php echo esc_html( (string) $variation['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php else : ?>
			<input type="hidden" name="variation_id" value="0" data-mjy="variation-id" />
		<?php endif; ?>

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
					<label for="<?php echo esc_attr( $form_id . '-name' ); ?>">نام و نام خانوادگی</label>
					<input
						type="text"
						id="<?php echo esc_attr( $form_id . '-name' ); ?>"
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
					<label for="<?php echo esc_attr( $form_id . '-email' ); ?>">ایمیل</label>
					<input
						type="email"
						id="<?php echo esc_attr( $form_id . '-email' ); ?>"
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
					<label for="<?php echo esc_attr( $form_id . '-phone' ); ?>">شمارهٔ موبایل</label>
					<input
						type="tel"
						id="<?php echo esc_attr( $form_id . '-phone' ); ?>"
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
			<label for="<?php echo esc_attr( $form_id . '-website' ); ?>">این فیلد را خالی بگذارید</label>
			<input type="text" id="<?php echo esc_attr( $form_id . '-website' ); ?>" name="website" tabindex="-1" autocomplete="off" />
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
			<button type="submit" class="mjy-button" disabled><?php echo esc_html( (string) $settings['button'] ); ?></button>
		</p>

		<p class="mjy-form__message" role="status" aria-live="polite" data-mjy="message"></p>
	</form>

	<noscript>
		<p class="mjy-form__intro">
			برای ثبت درخواست، جاوااسکریپت مرورگر باید فعال باشد. می‌توانید با پشتیبانی فروشگاه تماس بگیرید.
		</p>
	</noscript>
</section>
