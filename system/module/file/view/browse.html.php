<?php include TPL_ROOT . 'common/header.modal.html.php';?>
<?php if(isset($this->config->site->score) and $this->config->site->score == 'open'):?>
<form id='ajaxForm' method='post' action='<?php echo inlink('score')?>'>
<?php endif;?>
<table class='table table-bordered'>
  <thead>
    <tr>
      <th class='text-center'><?php echo $lang->file->id;?></th>
      <th class='text-center'><?php echo $lang->file->common;?></th>
      <th class='text-center'><?php echo $lang->file->extension;?></th>
      <th class='text-center'><?php echo $lang->file->size;?></th>
      <th class='text-center'><?php echo $lang->file->addedBy;?></th>
      <th class='text-center'><?php echo $lang->file->addedDate;?></th>
      <th class='text-center'><?php echo $lang->file->downloads;?></th>
      <?php if(isset($this->config->site->score) and $this->config->site->score == 'open'):?>
      <th class='text-center'><?php echo $lang->file->score;?></th>
      <?php endif;?>
      <th class='text-center'><?php echo $lang->actions;?></th>
    </tr>          
  </thead>
  <tbody>
    <?php foreach($files as $file):?>
    <tr class='text-middle'>
      <td><?php echo $file->id;?></td>
      <td>
        <?php
        if($file->isImage)
        {
            echo html::a(inlink('download', "id=$file->id"), html::image($file->smallURL, "class='image-small' title='{$file->title}'"), "target='_blank'");
            if($file->primary == 1) echo '<small class="label label-success">'. $lang->file->primary .'</small>';
        }
        else
        {
            echo html::a(inlink('download', "id=$file->id"), "{$file->title}.{$file->extension}", "target='_blank'");
        }
        ?>
      </td>
      <td><?php echo $file->extension;?></td>
      <td><?php echo $file->size;?></td>
      <td><?php echo $file->addedBy;?></td>
      <td><?php echo $file->addedDate;?></td>
      <td><?php echo $file->downloads;?></td>
      <?php if(isset($this->config->site->score) and $this->config->site->score == 'open'):?>
      <td><?php echo html::input("scores[{$file->id}]", $file->score, 'size=2');?></td>
      <?php endif;?>
      <td>
      <?php
      echo html::a(inlink('edit',   "id=$file->id"), $lang->edit, "class='edit'");
      echo html::a(inlink('delete', "id=$file->id"), $lang->delete, "class='deleter'");
      if($file->isImage) echo html::a(inlink('setPrimary', "id=$file->id"), $lang->file->setPrimary, "class='option'");
      ?>
      </td>
    </tr>
    <?php endforeach;?>
    <?php if(isset($this->config->site->score) and $this->config->site->score == 'open'):?>
    <tr><td colspan='9'><?php echo html::submitButton($lang->file->setScore);?></td></tr>
    <?php endif;?>
  </tbody>

</table>
<?php if(isset($this->config->site->score) and $this->config->site->score == 'open'):?>
</form>
<?php endif;?>
<form id="fileForm" method='post' enctype='multipart/form-data' action='<?php echo inlink('upload', "objectType=$objectType&objectID=$objectID");?>'>
  <table class='table table-form'>
    <?php if($writeable):?>
    <tr>
      <td class='text-middle'><?php echo $lang->file->upload . sprintf($lang->file->limit, $this->config->file->maxSize / 1024 /1024);?></td>
      <td><?php echo $this->fetch('file', 'buildForm');?></td>
    </tr>
    <tr><td colspan='2' class='text-center'><?php echo html::submitButton();?></td></tr>
    <?php else:?>
    <tr><td colspan='2'><h5 class='text-danger'><?php echo $lang->file->errorUnwritable;?></h5></td></tr>
    <?php endif;?>
  </table>
</form>
<?php include TPL_ROOT . 'common/footer.modal.html.php';?>
