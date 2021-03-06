<?php
if(!defined('IN_DZZ')) {
    exit('Access Denied');
}
class table_resources_version extends dzz_table
{
    public function __construct()
    {

        $this->_table = 'resources_version';
        $this->_pk  = 'vid';
        parent::__construct();
    }
    //获取文件图标
    public function getfileimg($data){
        if($data['type']=='image'){
            $data['img']=DZZSCRIPT.'?mod=io&op=thumbnail&size=small&path='.dzzencode('attach::'.$data['aid']);
            //$data['url']=DZZSCRIPT.'?mod=io&op=thumbnail&size=large&path='.dzzencode('attach::'.$data['aid']);
        }elseif($data['type']=='attach' || $data['type']=='document'){
            $data['img']=geticonfromext($data['ext'],$data['type']);
            //$data['url']=DZZSCRIPT.'?mod=io&op=getStream&path='.dzzencode('attach::'.$data['aid']);
        }elseif($data['type']=='dzzdoc'){
            //$data['url']=DZZSCRIPT.'?mod=document&icoid='.dzzencode('attach::'.$data['aid']);
            $data['img']=isset($data['img'])?$data['img']:geticonfromext($data['ext'],$data['type']);
        }else{
            $data['img']=isset($data['img'])?$data['img']:geticonfromext($data['ext'],$data['type']);
        }
        return $data['img'];
    }
   public function fetch_all_by_rid($rid){
       $rid = trim($rid);
       $versions = array();
       $resources = C::t('resources')->fetch_info_by_rid($rid);
       if($resources['vid'] == 0){
           $attrdata = C::t('resources_attr')->fetch_by_rid($rid,0);
            $filedata = array(
                'vid' =>0,
                'rid'=>$rid,
                'uid'=>$resources['uid'],
                'username'=>$resources['username'],
                'vname'=>'',
                'aid'=>$attrdata['aid'],
                'type'=>$resources['type'],
                'ext'=>$resources['ext'],
                'size'=>$resources['size'],
                'dateline'=>$resources['dateline'],
                'img'=>$attrdata['img']
            );
           $filedata['img'] = self::getfileimg($filedata);
           $versions[$filedata['vid']] = $filedata;
       }else{
           foreach(DB::fetch_all("select * from %t where rid = %s",array($this->_table,$rid)) as $val){
               $attrdata = C::t('resources_attr')->fetch_by_rid($rid,$val['vid']);
               $val['img'] = isset($attrdata['img']) ?$attrdata['img']:'';
               $filedata = $val;
               $filedata['img'] = self::getfileimg($filedata);
               $versions[$val['vid']] = $filedata;
           }
       }
       return $versions;
   }
    public  function  delete_by_version($icoid,$vid){
        global $_G ;
        if(parent::delete($vid)){
           DB::delete('resources_attr',array('rid'=>$icoid,'vid'=>$vid));
            $eventdata = array(
                'rid'=>$icoid,
                'uid'=>$_G['uid'],
                'username'=>$_G['username'],
                'event_body'=>'delete_version',
                'body_data'=>serialize(array('version'=>$vid)),
                'dateline'=>TIMESTAMP,
            );
           DB::insert('resources_event',$eventdata);
            $v = DB::result_first("select vid from %t where rid=%s order by dateline DESC ",array($this->_table,$icoid));
            DB::update('resources',array('vid'=>$v),array('rid'=>$icoid));
            return array('msg'=>$v);
        }else return array('error'=>lang('error_delete_version_failed'));
    }
    public function delete_by_rid($rid,$data=array()){
       // if(!is_array($rid)) $rid = (array)$rid;
        if(!$return = DB::fetch_all("select * from %t where rid = %s",array($this->_table,$rid))){
            return ;
        }
        $aids = array();
        $totalsize = 0;
        foreach($return as $v){
            $aids[] = $v['aid'];
            $totalsize += intval($v['size']);
        }
        if($data['size']) SpaceSize(-$totalsize,$data['gid'],1,$data['uid']);
        if(!empty($aids)){
            C::t('attachment')->addcopy_by_aid($aids,-1);
        }
        DB::delete($this->_table,'rid in('.dimplode($rid).')');
    }
    //上传新版本
    public function add_new_version_by_rid($rid,$setarr){
        global $_G,$documentexts;
        if(!$resources = C::t('resources')->fetch_info_by_rid($rid)){
            return array('error'=>lang('file_not_exist'));
        }
        //检测权限
        if(perm_check::checkperm_Container($resources['pfid'],'upload')){
            if(!perm_check::checkperm_Container($resources['pfid'],'edit2')){
                return array('error'=>lang('no_privilege'));
            }elseif($resources['uid'] == $setarr['uid'] && !perm_check::checkperm_Container($resources['pfid'],'edit1')){
                return array('error'=>lang('no_privilege'));
            }
        }else{
            return array('error'=>lang('no_privilege'));
        }
        //文件类型获取
        $imgexts = array('jpg', 'jpeg', 'gif', 'png', 'bmp');
        if (in_array(strtolower($setarr['ext']), $imgexts)){
            $setarr['type'] = 'image';
        }elseif(in_array(strtoupper($setarr['ext']), $documentexts)){
            $setarr['type'] = 'document';
        }
        $oldattr = C::t('resources_attr')->fetch_by_rid($rid);
        //没有版本时,属性表和版本数据处理
        if($resources['vid'] == 0){
            $setarr1 = array(
                'rid'=>$rid,
                'uid'=>$resources['uid'],
                'username'=>$resources['username'],
                'vname'=>'',
                'size'=>$resources['size'],
                'ext'=>$resources['ext'],
                'type'=>$resources['type'],
                'dateline'=>$resources['dateline'],
                'aid'=>isset($oldattr['aid'])? $oldattr['aid']:''
            );
            //将原数据插入版本表
            $oldvid = parent::insert($setarr1,1);
            //更新原属性表数据
            DB::update('resources_attr',array('vid'=>$oldvid),array('rid'=>$rid,'vid'=>0));
        }

        //文件名
        $filename = $setarr['name'];
        $filename = self::getFileName($setarr['name'],$resources['pfid']);
        unset($setarr['name']);

        $setarr['rid'] = $rid;

        //新数据插入版本表
        if($vid = parent::insert($setarr,1)){

            //更新主表数据
            if(DB::update('resources',array('vid'=>$vid,'size'=>$setarr['size'],'ext'=>$setarr['ext'],'type'=>$setarr['type'],'name'=>$filename),array('rid'=>$rid))){
                SpaceSize($setarr['size'],$resources['gid'],true);
                //插入属性表数据
                $sourceattrdata = array(
                    'postip' => $_G['clientip'],
                    'title' => $filename,
                    'aid' => isset($setarr['aid']) ? $setarr['aid'] : '',
                    'img'=>geticonfromext($setarr['ext'],$setarr['type'])
                );
                //插入属性表
                if (C::t('resources_attr')->insert_attr($rid,$vid,$sourceattrdata)) {
                    if ($setarr['aid']) {
                        $attach = C::t('attachment')->fetch($setarr['aid']);
                        C::t('attachment')->update($setarr['aid'], array('copys' => $attach['copys'] + 1));//增加使用数
                    }
                }
                //记录事件
                $path = C::t('resources_path')->fetch_pathby_pfid($resources['pfid']);
                $path = preg_replace('/dzz:(.+?):/','',$path);
                $event = 'update_version';
                $eventdata = array(
                    'title' => $resources['name'],
                    'aid' => $setarr['aid'],
                    'username' => $setarr['username'],
                    'uid' => $setarr['uid'],
                    'position'=>$path
                );
                C::t('resources_event')->addevent_by_pfid($resources['pfid'], $event, 'updatevesion', $eventdata, $resources['gid'], $rid, $resources['name']);
                //增加统计数据
                $statis = array(
                    'edits'=>1,
                    'uid'=>$_G['uid'],
                    'editdateline'=>TIMESTAMP
                );
                c::t('resources_statis')->add_statis_by_rid($rid,$statis);
                $setarr['fdateline'] = dgmdate($setarr['dateline'],'Y-m-d H:i:s');
                $setarr['vid'] = $vid;
                $setarr['size'] = formatsize($setarr['size']);
                if($resources['vid'] == 0){
                    $setarr['olddatavid'] = $oldvid;
                }
                $setarr['dpath'] = dzzencode($rid);
                return $setarr;
            }else{
                parent::delete($vid);
                return array('error'=>lang('failure'));
            }
        }

    }
    //设置主版本
    public function set_primary_version_by_vid($vid){
        global $_G;
        if(!$versioninfo = parent::fetch($vid)){
            return array('error'=>lang('file_not_exist'));
        }
        $fileinfo = C::t('resources')->fetch_info_by_rid($versioninfo['rid']);

        //判断编辑权限
        if ($fileinfo['gid'] > 0) {
            $pfid = $fileinfo['pfid'];
            $perm = perm_check::getPerm($pfid);
            if ($perm > 0) {
                if (!perm_binPerm::havePower('edit2', $perm) && !(perm_binPerm::havePower('edit1', $perm) && $_G['uid'] == $fileinfo['uid'])) {
                    return array('error'=>lang('no_privilege'));
                }
            }
        }
        $vfilename = DB::result_first("select sval from %t where vid = %d and rid = %s and skey = %s",array('resources_attr',$vid,$versioninfo['rid'],'title'));

        //获取不重复的名字
        $filename = self::getFileName($vfilename,$fileinfo['pfid']);
        if(!$filename){
            $filename = $versioninfo['vname'];
            if($filename != $vfilename){
                C::t('resources_attr')->update_by_skey($fileinfo['rid'],$vid,array('title'=>$filename));
            }
        }
        //更改resources表数据
        $updatearr =  array('vid'=>$vid,'name'=>$filename,'size'=>$versioninfo['size'],'ext'=>$versioninfo['ext'],'type'=>$versioninfo['type']);
        if(DB::update('resources',$updatearr,array('rid'=>$versioninfo['rid']))){
            //文件路径信息
            $path = C::t('resources_path')->fetch_pathby_pfid($fileinfo['pfid']);
            $path = preg_replace('/dzz:(.+?):/','',$path);
            $event = 'setprimary_version';
            $hash = C::t('resources_event')->get_showtpl_hash_by_gpfid($fileinfo['pfid'],$fileinfo['gid']);
            $eventdata = array(
                'name' => $filename,
                'oldname'=>$fileinfo['name'],
                'aid' => $versioninfo['aid'],
                'username' => $_G['username'],
                'uid' => $_G['uid'],
                'position'=>$path,
                'hash'=>$hash
            );
            $statis = array(
                'edits'=>1,
                'uid'=>$_G['uid'],
                'editdateline'=>TIMESTAMP
            );
            C::t('resources_statis')->add_statis_by_rid($versioninfo['rid'],$statis);
            C::t('resources_event')->addevent_by_pfid($fileinfo['pfid'], $event, 'setprimaryversion', $eventdata, $fileinfo['gid'], $fileinfo['rid'], $fileinfo['name']);
            return array('rid'=>$versioninfo['rid']);
        }else{
            return array('error'=>lang('explorer_do_failed'));
        }

    }
    //判断文件重名
    public function getFileName($name,$pfid){
        static $i=0;
        $name=self::name_filter($name);
        if(DB::result_first("select COUNT(*) from %t where type!='folder' and name=%s and isdelete<1 and pfid=%d",array('resources',$name,$pfid))){
            $ext='';
            $namearr=explode('.',$name);
            if(count($namearr)>1){
                $ext=$namearr[count($namearr)-1];
                unset($namearr[count($namearr)-1]);
                $ext=$ext?('.'.$ext):'';
            }
            $tname=implode('.',$namearr);
            $name=preg_replace("/\(\d+\)/i",'',$tname).'('.($i+1).')'.$ext;
            $i+=1;
            return self::getFileName($name,$pfid);
        }else{
            return $name;
        }
    }
    //过滤文件名称
    public function name_filter($name){
        return str_replace(array('/','\\',':','*','?','<','>','|','"',"\n"),'',$name);
    }

    //根据版本id修改版本名称
    public function update_versionname_by_vid($vid,$vname){
        global $_G;
        if(!$versioninfo = parent::fetch($vid)){
            return array('error'=>lang('file_not_exist'));
        }
        if(DB::result_first("select count(*) from %t where vname = %s and rid = %s",array($this->_table,$vname,$versioninfo['rid'])) > 0 ){
            return array('error'=>lang('explorer_name_repeat'));
        }
        //文件基本信息
        $fileinfo = C::t('resources')->fetch_info_by_rid($versioninfo['rid']);

        //判断编辑权限
        if ($fileinfo['gid'] > 0) {
            $pfid = $fileinfo['pfid'];
            $perm = perm_check::getPerm($pfid);
            if ($perm > 0) {
                if (!perm_binPerm::havePower('edit2', $perm) && !(perm_binPerm::havePower('edit1', $perm) && $_G['uid'] == $fileinfo['uid'])) {
                    return array('error'=>lang('no_privilege'));
                }
            }
        }
        $sertarr = array('vname'=>$vname,'dateline'=>TIMESTAMP);
        if(parent::update($vid,$sertarr)){
            $path = C::t('resources_path')->fetch_pathby_pfid($fileinfo['pfid']);
            $path = preg_replace('/dzz:(.+?):/','',$path);
            $event = 'edit_versionname';
            $hash = C::t('resources_event')->get_showtpl_hash_by_gpfid($fileinfo['pfid'],$fileinfo['gid']);
            $eventdata = array(
                'name' => $vname,
                'filename'=>$fileinfo['name'],
                'username' => $_G['username'],
                'oldvname'=>($versioninfo['name']) ? $versioninfo['name']:dgmdate($versioninfo['dateline'],'Y-m-d H:i:s'),
                'uid' => $_G['uid'],
                'position'=>$path,
                'hash'=>$hash
            );
            $statis = array(
                'edits'=>1,
                'uid'=>$_G['uid'],
                'editdateline'=>TIMESTAMP
            );
            C::t('resources_statis')->add_statis_by_rid($versioninfo['rid'],$statis);
            C::t('resources_event')->addevent_by_pfid($fileinfo['pfid'], $event, 'editversionname', $eventdata, $fileinfo['gid'], $fileinfo['rid'], $fileinfo['name']);
            return array('vid'=>$vid,'primaryvid'=>$fileinfo['vid'],'fdateline'=>dgmdate($versioninfo['dateline'],'Y-m-d H:i:s'));
        }else{
            return array('error'=>lang('explorer_do_failed'));
        }
    }

    //根据rid修改版本名称,因版本表无数据,需先将主表数据放入版本表，然后更新主表和属性表
    public function update_versionname_by_rid($rid,$vname){
        global $_G;
        if(!$fileinfo = C::t('resources')->fetch_info_by_rid($rid)){
            return array('error'=>lang('file_not_exist'));
        }
        //判断编辑权限
        if ($fileinfo['gid'] > 0) {
            $pfid = $fileinfo['pfid'];
            $perm = perm_check::getPerm($pfid);
            if ($perm > 0) {
                if (!perm_binPerm::havePower('edit2', $perm) && !(perm_binPerm::havePower('edit1', $perm) && $_G['uid'] == $fileinfo['uid'])) {
                    return array('error'=>lang('no_privilege'));
                }
            }
        }
        //没有版本时,属性表和版本数据处理
        $setarr = array(
            'rid'=>$rid,
            'uid'=>$fileinfo['uid'],
            'username'=>$fileinfo['username'],
            'vname'=>$vname,
            'size'=>$fileinfo['size'],
            'ext'=>$fileinfo['ext'],
            'type'=>$fileinfo['type'],
            'dateline'=>TIMESTAMP
        );
        //将数据插入版本表
        if($vid = parent::insert($setarr,1)){
            //更新属性表数据
            DB::update('resources_attr',array('vid'=>$vid),array('rid'=>$rid,'vid'=>0));
            //更新主表数据
            if(DB::update('resources',array('vid'=>$vid),array('rid'=>$rid))){

                $path = C::t('resources_path')->fetch_pathby_pfid($fileinfo['pfid']);
                $path = preg_replace('/dzz:(.+?):/','',$path);
                $event = 'edit_versionname';
                $vfilename = DB::result_first("select sval from %t where vid = %d and rid = %s and skey = %s",array('resources_attr',$vid,$fileinfo['rid'],'title'));
                $hash = C::t('resources_event')->get_showtpl_hash_by_gpfid($fileinfo['pfid'],$fileinfo['gid']);
                $eventdata = array(
                    'name' => $vname,
                    'filename'=>$fileinfo['name'],
                    'username' => $_G['username'],
                    'oldvname'=>($fileinfo['name']) ? $fileinfo['name']:dgmdate($fileinfo['dateline'],'Y-m-d H:i:s'),
                    'uid' => $_G['uid'],
                    'position'=>$path,
                    'hash'=>$hash
                );
                $statis = array(
                    'edits'=>1,
                    'uid'=>$_G['uid'],
                    'editdateline'=>TIMESTAMP
                );
                C::t('resources_statis')->add_statis_by_rid($fileinfo['rid'],$statis);
                C::t('resources_event')->addevent_by_pfid($fileinfo['pfid'], $event, 'editversionname', $eventdata, $fileinfo['gid'], $fileinfo['rid'], $fileinfo['name']);
                return array('vid'=>$vid,'primaryvid'=>$fileinfo['vid'],'fdateline'=>dgmdate($setarr['dateline'],'Y-m-d H:i:s'));
            }else{
                parent::delete($vid);
                return array('error'=>lang('explorer_do_failed'));
            }
        }else{
            return array('error'=>lang('explorer_do_failed'));
        }
    }
    public function get_versioninfo_by_rid_vid($rid,$vid=0){
        $rid = trim($rid);
        $vid = intval($vid);
        if(!$rid) return ;
        if(!$vid){
            return C::t('resources')->fetch_info_by_rid($rid);
        }
        return DB::fetch_first("select * from %t where rid = %s and vid = %d",array($this->_table,$rid,$vid));
    }
    public function fetch_version_by_rid_vid($rid,$vid){
        $rid = trim($rid);
        $vid = intval($vid);
        $data = array();
       if(!$data = C::t('resources')->fetch_info_by_rid($rid)){
            return $data;
        }
        $versiondata = DB::fetch_first("select * from %t where rid = %s and vid = %d",array($this->_table,$rid,$vid));
        $data = array_merge($data,$versiondata);
        $attrdata = C::t('resources_attr')->fetch_by_rid($rid,$vid);
        $data = array_merge($data,$attrdata);
        $data['icoid'] = dzzencode('attach::' . $data['aid']);
        return $data;
    }

}