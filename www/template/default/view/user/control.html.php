<?php include TPL_ROOT . 'common/header.html.php';?>
<div class="page-user-control">
  <div class="row">
    <?php include '././side.html.php';?>
    <div class="col-md-10">
      <div class="panel panel-body">
        <div class="jumbotron-bg">
          <?php printf($lang->user->control->welcome, $this->app->user->realname);?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include TPL_ROOT . 'common/footer.html.php';?>
