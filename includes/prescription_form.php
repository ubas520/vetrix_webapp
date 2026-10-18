<section class="soft-card vet-form-card vet-entry-card" id="prescriptionFormPanel" <?=$prescriptionFormOpen?'':'hidden'?>>
    <div class="vet-form-title clinical-form-title"><h3>Add Prescription</h3></div>
    <p>Add medication instructions to an existing visit. Diagnosis, treatment, and clinical notes stay in Medical Records.</p>
    <?php if($prescriptionError!==''):?><div class="alert alert-danger" role="alert"><?=e($prescriptionError)?></div><?php endif;?>
    <?php if(!$consultationOptions):?>
    <p>No consultations are waiting for a prescription.</p>
    <a class="button-primary" href="<?=app_url('vet/medical_records.php?action=add')?>">Create a consultation</a>
    <?php else:?>
    <form method="POST" class="form-stack" id="prescriptionCreator">
        <?=csrf_field()?>
        <label>Consultation<select class="form-select" name="record_id" required>
            <option value="">Choose a visit</option>
            <?php foreach($consultationOptions as $option):?><option value="<?=$option['id']?>" <?=$recordFocus===(int)$option['id']?'selected':''?>><?=e($option['name'].' — '.$option['owner_name'].' — '.$option['visit_date'].' — '.($option['diagnosis']?:'General consultation'))?></option><?php endforeach;?>
        </select></label>
        <label>Medicine<input class="form-control" name="medicine" value="<?=e($medication['medicine'])?>" required></label>
        <div class="form-grid-two">
            <label>Dose<input class="form-control" name="dose" value="<?=e($medication['dose'])?>" required></label>
            <label>Frequency<input class="form-control" name="frequency" value="<?=e($medication['frequency'])?>" required></label>
        </div>
        <label>Duration<input class="form-control" name="duration" value="<?=e($medication['duration'])?>" required></label>
        <label>Additional instructions (optional)<textarea class="form-control" name="instructions" rows="3"><?=e($medication['instructions'])?></textarea></label>
        <button class="button-primary" type="submit">Save prescription</button>
    </form>
    <?php endif;?>
    <button class="button-secondary mt-2" type="button" id="prescriptionFormClose">Close form</button>
</section>
