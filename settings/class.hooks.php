<?php if(!defined('APPLICATION')) exit();

/**
 * A special function that is automatically run upon enabling your application.
 *
 * Remember to rename this to FooHooks, where 'Foo' is you app's short name.
 */
class YagaHooks implements Gdn_IPlugin {

  static $_ReactionModel = NULL;

  /**
   * Display points in the user info list
   * @param type $Sender
   */
  public function UserInfoModule_OnBasicInfo_Handler($Sender) {
    $Model = new YagaModel();
    $Points = $Model->GetUserPoints($Sender->User->UserID);
    echo Wrap(T('Yaga.Points', 'Points'), 'dt') . ' ' . Wrap($Points, 'dd');
  }

  /**
   * Display the reaction counts on the profile page
   * @param type $Sender
   */
  public function ProfileController_AfterUserInfo_Handler($Sender) {
    $User = $Sender->User;
    //decho($User);
    echo '<div class="Yarbs ReactionsWrap">';
    echo Wrap(T('Yarbs.Reactions.Title', 'Reactions'), 'h2', array('class' => 'H'));
    
    // insert the reaction totals in the profile
    $Actions = $this->_ReactionModel->GetActions();
    $String = '';
    foreach($Actions as $Action) {
      $Count = $this->_ReactionModel->GetUserReactionCount($User->UserID, $Action->ActionID);
      $TempString = Wrap(Wrap(Gdn_Format::BigNumber($Count), 'span', array('title' => $Count)), 'span', array('class' => 'Yarbs_ReactionCount CountTotal'));
      $TempString .= Wrap($Action->Name, 'span', array('class' => 'Yarbs_ReactionName CountLabel'));

      $String .= Wrap(Wrap(Anchor($TempString, '/profile/yarbs/' . $User->UserID . '/' . $User->Name . '/' . $Action->ActionID, array('class' => 'Yarbs_Reaction TextColor', 'title' => $Action->Description)), 'span', array('class' => 'CountItem')), 'span', array('class' => 'CountItemWrap'));
    }
    
    echo Wrap($String, 'div', array('class' => 'DataCounts'));
  }
  
  /**
   * Display a record of reactions after the first post
   * @param DiscussionController $Sender
   */
  public function DiscussionController_AfterDiscussionBody_Handler($Sender) {
    $Type = 'discussion';
    $ID = $Sender->DiscussionID;
    $this->_RenderCurrentReactions($ID, $Type);
  }

  /**
   * Display a record of reactions after comments
   * @param DiscussionController $Sender
   */
  public function DiscussionController_AfterCommentBody_Handler($Sender) {
    $Type = 'comment';
    $ID = $Sender->EventArguments['Comment']->CommentID;
    $this->_RenderCurrentReactions($ID, $Type);
  }

  /**
   * Renders the reaction record for a specific item
   * @param int $ID
   * @param enum $Type 'discussion', 'activity', or 'comment'
   */
  protected function _RenderCurrentReactions($ID, $Type) {
    // check to see if allowed to view reactions
    if(!Gdn::Session()->CheckPermission('Plugins.Reactions.View')) {
      return;
    }

    if(empty($this->_ReactionModel)) {
      $this->_ReactionModel = new ReactionModel();
    }

    $Reactions = $this->_ReactionModel->GetReactions($ID, $Type);
    foreach($Reactions as $Reaction) {
      if($Reaction->UserIDs) {
        foreach($Reaction->UserIDs as $Index => $UserID) {
          $User = Gdn::UserModel()->GetID($UserID);
          $String = UserPhoto($User, array('Size' => 'Small'));
          $String .= '<span class="ReactSprite Reaction-' . $Reaction->ActionID . ' ' . $Reaction->CssClass . '"></span>';
          $Wrapttributes = array(
              'class' => 'UserReactionWrap',
              'data-userid' => $User->UserID,
              'title' => $User->Name . ' - ' . $Reaction->Name . ' on ' . Gdn_Format::Date($Reaction->Dates[$Index], '%B %e, %Y')
          );
          echo Wrap($String, 'span', $Wrapttributes);
        }
      }
    }
  }

  /**
   * Add and action list to discussion items
   * @param DiscussionController $Sender
   */
  public function DiscussionController_AfterReactions_Handler($Sender) {
    if(C('Yaga.Reactions.Enabled') == FALSE) {
      return;
    }

    $Sender->AddJsFile('reactions.js', 'yaga');
    $Sender->AddCssFile('reactions.css', 'yaga');
    // check to see if allowed to add reactions
    if(!Gdn::Session()->CheckPermission('Plugins.Reactions.Add')) {
      return;
    }

    // Users shouldn't be able to react to their own content
    $Type = $Sender->EventArguments['RecordType'];
    $ID = $Sender->EventArguments['RecordID'];

    // Users shouldn't be able to react to their own content
    if(Gdn::Session()->UserID != $Sender->EventArguments['Author']->UserID) {
      $this->_RenderActions($ID, $Type);
    }
  }

  /**
   * Add the action list to any activity items that can be commented on
   * @param ActivityController $Sender
   */
  public function ActivityController_AfterActivityBody_Handler($Sender) {
    $Activity = $Sender->EventArguments['Activity'];
    $CurrentUserID = Gdn::Session()->UserID;
    $Type = 'activity';
    $ID = $Activity->ActivityID;

    // Only allow reactions on activities that allow comments
    if($Activity->AllowComments == 0) {
      return;
    }

    // check to see if allowed to add reactions
    if(!Gdn::Session()->CheckPermission('Plugins.Reactions.Add')) {
      return;
    }

    // Activities can be by multiple users
    if(is_array($Activity->ActivityUserID) && in_array($CurrentUserID, $Activity->ActivityUserID)) {
      // User is part of a multiple user activity
    }
    else if($CurrentUserID == $Activity->ActivityUserID) {
      // User is the author of this activity
    }
    else {
      echo Wrap($this->_RenderActions($ID, $Type, FALSE), 'div', array('class' => 'Reactions'));
    }
  }

  /**
   * Renders an action list that also contains the current count of reactions
   * an item has received
   * @param int $ID
   * @param enum $Type 'discussion', 'activity', or 'comment'
   * @param bool $Echo Should it be echoed?
   * @return mixed String if $Echo is false, TRUE otherwise
   */
  public function _RenderActions($ID, $Type, $Echo = TRUE) {
    if(empty($this->_ReactionModel)) {
      $this->_ReactionModel = new ReactionModel();
    }

    $Reactions = $this->_ReactionModel->GetReactions($ID, $Type);
    //decho($Reactions);
    $ActionsString = '';
    foreach($Reactions as $Action) {
      $ActionsString .= Anchor(
              Wrap('&nbsp;', 'span', array('class' => 'ReactSprite React-' . $Action->ActionID . ' ' . $Action->CssClass)) .
              WrapIf(count($Action->UserIDs), 'span', array('class' => 'Count')) .
              Wrap($Action->Name, 'span', array('class' => 'ReactLabel')), 'react/' . $Type . '/' . $ID . '/' . $Action->ActionID, 'Hijack ReactButton'
      );
    }

    $AllActionsString = Wrap($ActionsString, 'span', array('class' => 'ReactMenu'));

    if($Echo) {
      echo $AllActionsString;
      return true;
    }
    else {
      return $AllActionsString;
    }
  }

  /**
   * Insert JS and CSS files into the appropiate controllers
   */
  public function ProfileController_Render_Before($Sender) {
    $this->_AddResources($Sender);
  }

  public function DiscussionController_Render_Before($Sender) {
    $this->_AddResources($Sender);
  }

  public function CommentController_Render_Before($Sender) {
    $this->_AddResources($Sender);
  }
  
  private function _AddResources($Sender) {
    if(empty($this->_ReactionModel)) {
      $this->_ReactionModel = new ReactionModel();
    }
    
    $Sender->AddCssFile('reactions.css', 'yaga');
  }
  
  /**
   * Add resources to all dashboard pages
   * @param type $Sender
   */
  public function Base_Render_Before($Sender) {
      if($Sender->MasterView == 'admin') {
        $Sender->AddCssFile('yaga.css', 'yaga');
      }
   }
   
  /**
   * Special function automatically run upon clicking 'Enable' on your application.
   */
  public function Setup() {
    $Config = Gdn::Factory(Gdn::AliasConfig);
    $Drop = C('Yaga.Version') === FALSE ? TRUE : FALSE;
    $Explicit = TRUE;
    include(PATH_APPLICATIONS . DS . 'yaga' . DS . 'settings' . DS . 'structure.php');
    include(PATH_APPLICATIONS . DS . 'yaga' . DS . 'settings' . DS . 'stub.php');

    $ApplicationInfo = array();
    include(CombinePaths(array(PATH_APPLICATIONS . DS . 'yaga' . DS . 'settings' . DS . 'about.php')));
    $Version = ArrayValue('Version', ArrayValue('Yaga', $ApplicationInfo, array()), 'Undefined');
    SaveToConfig('Yaga.Version', $Version);
  }

  /**
   * Add the settings page links
   * @param Mixed $Sender
   */
  public function Base_GetAppSettingsMenuItems_Handler($Sender) {
    $Menu = $Sender->EventArguments['SideMenu'];
    $Section = 'Gamification';
    $Attrs = array('class' => $Section);
    $Menu->AddItem($Section, $Section, FALSE, $Attrs);
    $Menu->AddLink($Section, 'Settings', 'configure', 'Garden.Settings.Manage');
    if(C('Yaga.Reactions.Enabled')) {
      $Menu->AddLink($Section, 'Reactions', 'actions/settings', 'Yaga.Reactions.Manage');
    }
    if(C('Yaga.Badges.Enabled')) {
      $Menu->AddLink($Section, 'Badges', 'badges/settings', 'Yaga.Badges.Manage');
    }
    if(C('Yaga.Ranks.Enabled')) {
      $Menu->AddLink($Section, 'Ranks', 'ranks/settings', 'Yaga.Ranks.Manage');
    }
  }

  /**
   * Special function automatically run upon clicking 'Disable' on your application.
   */
  public function OnDisable() {
    // Optional. Delete this if you don't need it.
  }

  /**
   * Special function automatically run upon clicking 'Remove' on your application.
   */
  public function CleanUp() {
    // Optional. Delete this if you don't need it.
  }

}