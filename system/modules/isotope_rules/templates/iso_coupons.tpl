<h2><?php echo $this->headline; ?></h2>
<?php if ($this->message != ''): ?>
<p class="<?php echo $this->mclass; ?>"><?php echo $this->message; ?></p>
<?php endif; ?>

<form action="<?php echo $this->action; ?>" method="get">
<div class="formbody">
<label for="ctrl_coupon_<?php echo $this->id; ?>"><?php echo $this->label; ?></label>
<input type="text" name="coupon_<?php echo $this->id; ?>" id="ctrl_coupon_<?php echo $this->id; ?>" class="tl_text" /> <input type="submit" value="<?php echo $this->sLabel; ?>" />
</div>
</form>