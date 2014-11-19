<?php
namespace MapasCulturais\Entities;

use Doctrine\ORM\Mapping as ORM;
use MapasCulturais\Traits;
use MapasCulturais\App;

/**
 * Registration
 * @property-read \MapasCulturais\Entities\Agent $owner The owner of this registration
 *
 * @ORM\Table(name="registration")
 * @ORM\Entity
 * @ORM\entity(repositoryClass="MapasCulturais\Repositories\Registration")
 * @ORM\HasLifecycleCallbacks
 */
class Registration extends \MapasCulturais\Entity
{
    use Traits\EntityMetadata,
        Traits\EntityFiles,
        Traits\EntityOwnerAgent,
        Traits\EntityAgentRelation;

    const STATUS_SENT = self::STATUS_ENABLED;
    const STATUS_APPROVED = 10;
    const STATUS_WAITLIST = 8;
    const STATUS_NOTAPPROVED = 3;
    const STATUS_INVALID = 2;

    protected static $validations = array(
        'owner' => array(
            'required' => "O agente responsável é obrigatório."
        )
    );

    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="SEQUENCE")
     * @ORM\SequenceGenerator(sequenceName="registration_id_seq", allocationSize=1, initialValue=1)
     */
    protected $id;


    /**
     * @var string
     *
     * @ORM\Column(name="category", type="string", length=255)
     */
    protected $category;


    /**
     * @var \MapasCulturais\Entities\Project
     *
     * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\Project", fetch="EAGER")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="project_id", referencedColumnName="id")
     * })
     */
    protected $project;


    /**
     * @var \MapasCulturais\Entities\Agent
     *
     * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\Agent", fetch="EAGER")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="agent_id", referencedColumnName="id")
     * })
     */
    protected $owner;


    /**
     * @var integer
     *
     * @ORM\Column(name="status", type="smallint", nullable=false)
     */
    protected $status = self::STATUS_DRAFT;

    /**
    * @ORM\OneToMany(targetEntity="MapasCulturais\Entities\RegistrationMeta", mappedBy="owner", cascade="remove", orphanRemoval=true)
    */
    protected $__metadata = array();

    function __construct() {
        $this->owner = App::i()->user->profile;
        parent::__construct();
    }

    function jsonSerialize() {
        $json = array(
            'id' => $this->id,
            'project' => $this->project->simplify('id,name,singleUrl'),
            'number' => $this->number,
            'owner' => $this->owner->simplify('id,name,singleUrl'),
            'agentRelations' => array(),
            'files' => array(),
            'singleUrl' => $this->singleUrl,
            'editUrl' => $this->editUrl,
            'status' => $this->status
        );

        $related_agents = $this->getRelatedAgents();

        foreach(App::i()->getRegisteredRegistrationAgentRelations() as $def){
            $json['agentRelations'][] = array(
                'label' => $def->label,
                'description' => $def->description,
                'agent' => isset($related_agents[$def->agentRelationGroupName]) ? $related_agents[$def->agentRelationGroupName][0]->simplify('id,name,singleUrl') : null
            );
        }

        foreach($this->files as $group => $file){
            $json['files'][$group] = $file->simplify('id,url,name,deleteUrl');
        }

        return $json;
    }

    function setOwnerId($id){
        $agent = App::i()->repo('Agent')->find($id);
        $this->owner = $agent;
    }

    function setProjectId($id){
        $agent = App::i()->repo('Project')->find($id);
        $this->project = $agent;
    }

    /**
     *
     * @return
     */
    protected function _getRegistrationOwnerRequest(){
        return  App::i()->repo('RequestChangeOwnership')->findOneBy(array('originType' => $this->getClassName(), 'originId' => $this->id));
    }

    function getRegistrationOwnerStatus(){
        if($request = $this->_getRegistrationOwnerRequest()){
            return RegistrationAgentRelation::STATUS_PENDING;
        }else{
            return RegistrationAgentRelation::STATUS_ENABLED;
        }
    }

    function getRegistrationOwner(){
        if($request = $this->_getRegistrationOwnerRequest()){
            return $request->agent;
        }else{
            return $this->owner;
        }
    }

    function getNumber(){
        if($this->id){
            return $this->project->id .'-'. str_pad($this->id,3,'0',STR_PAD_LEFT);
        }else{
            return null;
        }
    }

    function setStatus(){
        // do nothing
    }

    protected function _setStatusTo($status){
        $this->checkPermission('changeStatus');

        App::i()->applyHookBoundTo($this, 'entity(Registration).approve:before');

        $this->status = $status;

        $this->save(true);

        App::i()->applyHookBoundTo($this, 'entity(Registration).approve:after');
    }

    function setStatusToDraft(){
        $this->_setStatusTo(self::STATUS_DRAFT);
    }

    function setStatusToApproved(){
        $this->_setStatusTo(self::STATUS_APPROVED);
    }

    function setStatusToNotApproved(){
        $this->_setStatusTo(self::STATUS_NOTAPPROVED);
    }

    function setStatusToWaitlist(){
        $this->_setStatusTo(self::STATUS_WAITLIST);
    }

    function setStatusToInvalid(){
        $this->_setStatusTo(self::STATUS_INVALID);
    }


    protected function canUserView($user){
        if($user->is('guest')){
            return false;
        }

        if($user->is('admin')){
            return true;
        }

        if($this->canUser('@control', $user)){
            return true;
        }

        if($this->project->canUser('@control', $user)){
            return true;
        }

        foreach($this->relatedAgents as $agent){
            if($agent->canUser('@control', $user)){
                return true;
            }
        }

        return false;
    }

    protected function canUserChangeStatus($user){
        if($user->is('guest')){
            return false;
        }

        return $this->project->canUser('@control', $user);
    }

    //============================================================= //
    // The following lines ara used by MapasCulturais hook system.
    // Please do not change them.
    // ============================================================ //

    /** @ORM\PrePersist */
    public function prePersist($args = null){ parent::prePersist($args); }
    /** @ORM\PostPersist */
    public function postPersist($args = null){ parent::postPersist($args); }

    /** @ORM\PreRemove */
    public function preRemove($args = null){ parent::preRemove($args); }
    /** @ORM\PostRemove */
    public function postRemove($args = null){ parent::postRemove($args); }

    /** @ORM\PreUpdate */
    public function preUpdate($args = null){ parent::preUpdate($args); }
    /** @ORM\PostUpdate */
    public function postUpdate($args = null){ parent::postUpdate($args); }
}