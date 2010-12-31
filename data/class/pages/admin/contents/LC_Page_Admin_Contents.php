<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2010 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

// {{{ requires
require_once(CLASS_REALDIR . "pages/admin/LC_Page_Admin.php");

/**
 * コンテンツ管理 のページクラス.
 *
 * @package Page
 * @author LOCKON CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Contents extends LC_Page_Admin {

    // }}}
    // {{{ functions

    /**
     * Page を初期化する.
     *
     * @return void
     */
    function init() {
        parent::init();
        $this->tpl_mainpage = 'contents/index.tpl';
        $this->tpl_subnavi = 'contents/subnavi.tpl';
        $this->tpl_subno = "index";
        $this->tpl_mainno = 'contents';
        $this->arrForm = array(
            'year' => date('Y'),
            'month' => date('n'),
            'day' => date('j'),
        );
        $this->tpl_subtitle = '新着情報管理';
    }

    /**
     * Page のプロセス.
     *
     * @return void
     */
    function process() {
        $this->action();
        $this->sendResponse();
    }

    /**
     * Page のアクション.
     *
     * @return void
     */
    function action() {

        //---- ページ初期設定
        $objQuery = new SC_Query();
        $objDate = new SC_Date(ADMIN_NEWS_STARTYEAR);
        $objDb = new SC_Helper_DB_Ex();

        SC_Utils_Ex::sfIsSuccess(new SC_Session());

        //---- 日付プルダウン設定
        $this->arrYear = $objDate->getYear();
        $this->arrMonth = $objDate->getMonth();
        $this->arrDay = $objDate->getDay();

        if (!isset($_POST['mode'])) $_POST['mode'] = "";

        //---- 新規登録/編集登録
        if ( $_POST['mode'] == 'regist'){
            $_POST = $this->lfConvData($_POST);

            if ($this->arrErr = $this->lfErrorCheck()) {       // 入力エラーのチェック
                $this->arrForm = $_POST;
            } else {

                if (isset($_POST['link_method']) == ""){
                    $_POST['link_method'] = 1;
                }

                $this->registDate = $_POST['year'] ."/". $_POST['month'] ."/". $_POST['day'];

                //-- 編集登録
                if (strlen($_POST["news_id"]) > 0 && is_numeric($_POST["news_id"])) {

                    $this->lfNewsUpdate($objQuery);

                    //--　新規登録
                } else {
                    $this->lfNewsInsert($objQuery);
                }

                $this->tpl_onload = "window.alert('編集が完了しました');";
            }
        }

        //----　編集データ取得
        if ($_POST["mode"] == "search" && is_numeric($_POST["news_id"])) {
            $sql = "SELECT *, cast(news_date as date) as cast_news_date FROM dtb_news WHERE news_id = ? ";
            $result = $objQuery->getAll($sql, array($_POST["news_id"]));
            $this->arrForm = $result[0];

            $arrData = split("-", $result[0]["cast_news_date"]);
            $this->arrForm['year']  = $arrData[0];
            $this->arrForm['month'] = $arrData[1];
            $this->arrForm['day']   = $arrData[2];

            $this->edit_mode = "on";
        }

        //----　データ削除
        if ( $_POST['mode'] == 'delete' && is_numeric($_POST["news_id"])) {

            // rankを取得
            $pre_rank = $objQuery->getOne(" SELECT rank FROM dtb_news WHERE del_flg = 0 AND news_id = ? ", array( $_POST['news_id']  ));

            //-- 削除する新着情報以降のrankを1つ繰り上げておく
            $objQuery->begin();
            $sql = "UPDATE dtb_news SET rank = rank - 1, update_date = NOW() WHERE del_flg = 0 AND rank > ?";
            $objQuery->query( $sql, array( $pre_rank  ) );

            $sql = "UPDATE dtb_news SET rank = 0, del_flg = 1, update_date = NOW() WHERE news_id = ?";
            $objQuery->query( $sql, array( $_POST['news_id'] ) );
            $objQuery->commit();

            $this->objDisplay->reload();             //自分にリダイレクト（再読込による誤動作防止）
        }

        //----　表示順位移動

        if ( $_POST['mode'] == 'move' && is_numeric($_POST["news_id"]) ) {
            if ($_POST["term"] == "up") {
                $objDb->sfRankUp("dtb_news", "news_id", $_POST["news_id"]);
            } else if ($_POST["term"] == "down") {
                $objDb->sfRankDown("dtb_news", "news_id", $_POST["news_id"]);
            }
            //sf_rebuildIndex($conn);
            $this->objDisplay->reload();
        }

        //----　指定表示順位移動
        if ($_POST['mode'] == 'moveRankSet') {
            $key = "pos-".$_POST['news_id'];
            $input_pos = mb_convert_kana($_POST[$key], "n");
            if(SC_Utils_Ex::sfIsInt($input_pos)) {
                $objDb->sfMoveRank("dtb_news", "news_id", $_POST['news_id'], $input_pos);
                $this->objDisplay->reload();
            }
        }

        //---- 全データ取得
        $sql = "SELECT *, cast(news_date as date) as cast_news_date FROM dtb_news WHERE del_flg = '0' ORDER BY rank DESC";
        $this->list_data = $objQuery->getAll($sql);
        $this->line_max = count($this->list_data);
        $sql = "SELECT MAX(rank) FROM dtb_news WHERE del_flg = '0'";        // rankの最大値を取得
        $this->max_rank = $objQuery->getOne($sql);
    }


    /**
     * デストラクタ.
     *
     * @return void
     */
    function destroy() {
        parent::destroy();
    }


    //---- 入力文字列を配列へ
    function lfConvData( $data ){

        // 文字列の変換（mb_convert_kanaの変換オプション）
        $arrFlag = array(
                         "year" => "n"
                         ,"month" => "n"
                         ,"day" => "n"
                         ,"url" => "a"
                         ,"news_title" => "aKV"
                         ,"news_comment" => "aKV"
                         ,"link_method" => "n"
                         );

        if ( is_array($data) ){
            foreach ($arrFlag as $key=>$line) {
                $data[$key] = isset($data[$key])
                                      ? mb_convert_kana($data[$key], $line)
                                      : "";
            }
        }

        return $data;
    }

    //----　指定順位へ移動
    function sf_setRankPosition(&$objQuery, $tableName, $keyIdColumn, $keyId, $position) {

        // 自身のランクを取得する
        $objQuery->begin();
        $rank = $objQuery->getOne("SELECT rank FROM $tableName WHERE $keyIdColumn = ?", array($keyId));

        if( $position > $rank ) $term = "- 1";  //入れ替え先の順位が入れ換え元の順位より大きい場合
        if( $position < $rank ) $term = "+ 1";  //入れ替え先の順位が入れ換え元の順位より小さい場合

        //--　指定した順位の商品から移動させる商品までのrankを１つずらす
        $sql = "UPDATE $tableName SET rank = rank $term, update_date = NOW() WHERE rank BETWEEN ? AND ? AND del_flg = 0";
        if( $position > $rank ) $objQuery->query( $sql, array( $rank + 1, $position ) );
        if( $position < $rank ) $objQuery->query( $sql, array( $position, $rank - 1 ) );

        //-- 指定した順位へrankを書き換える。
        $sql  = "UPDATE $tableName SET rank = ?, update_date = NOW() WHERE $keyIdColumn = ? AND del_flg = 0 ";
        $objQuery->query( $sql, array( $position, $keyId ) );
        $objQuery->commit();
    }

    //---- 入力エラーチェック（順位移動用）
    function sf_errorCheckPosition(&$objQuery, $tableName, $position, $keyIdColumn, $keyId) {

        $objErr = new SC_CheckError();
        $objErr->doFunc( array("移動順位", "moveposition", 4 ), array( "ZERO_CHECK", "NUM_CHECK", "EXIST_CHECK", "MAX_LENGTH_CHECK" ) );

        // 自身のランクを取得する。
        $rank = $objQuery->getOne("SELECT rank FROM $tableName WHERE $keyIdColumn = ?", array($keyId));
        if ($rank == $position ) $objErr->arrErr["moveposition"] .= "※ 指定した移動順位は現在の順位です。";

        // rankの最大値以上の入力を許容しない
        if( ! $objErr->arrErr["position"] ) {
            $sql = "SELECT MAX( rank ) FROM " .$tableName. " WHERE del_flg = 0";
            $result = $objQuery->getOne($sql);
            if( $position > $result ) $objErr->arrErr["moveposition"] .= "※ 入力された順位は、登録数の最大値を超えています。";
        }

        return $objErr->arrErr;
    }

    //---- 入力エラーチェック
    function lfErrorCheck(){

        $objErr = new SC_CheckError();

        $objErr->doFunc(array("日付(年)", "year"), array("EXIST_CHECK"));
        $objErr->doFunc(array("日付(月)", "month"), array("EXIST_CHECK"));
        $objErr->doFunc(array("日付(日)", "day"), array("EXIST_CHECK"));
        $objErr->doFunc(array("日付", "year", "month", "day"), array("CHECK_DATE"));
        $objErr->doFunc(array("タイトル", 'news_title', MTEXT_LEN), array("EXIST_CHECK","MAX_LENGTH_CHECK"));
        $objErr->doFunc(array("本文", 'url', URL_LEN), array("MAX_LENGTH_CHECK"));
        $objErr->doFunc(array("本文", 'news_comment', LTEXT_LEN), array("MAX_LENGTH_CHECK"));

        return $objErr->arrErr;
    }

    //INSERT文
    function lfNewsInsert(&$objQuery){

        if ($_POST["link_method"] == "") {
            $_POST["link_method"] = 1;
        }

        //rankの最大+1を取得する
        $rank_max = $objQuery->getOne("SELECT MAX(rank) + 1 FROM dtb_news WHERE del_flg = '0'");

        $sql = "INSERT INTO dtb_news (news_id, news_date, news_title, creator_id, news_url, link_method, news_comment, rank, create_date, update_date)
            VALUES (?,?,?,?,?,?,?,?,now(),now())";
        $arrRegist = array($objQuery->nextVal('dtb_news_news_id'), $this->registDate, $_POST["news_title"], $_SESSION['member_id'],  $_POST["news_url"], $_POST["link_method"], $_POST["news_comment"], $rank_max);

        $objQuery->query($sql, $arrRegist);

        // 最初の1件目の登録はrankにNULLが入るので対策
        $sql = "UPDATE dtb_news SET rank = 1 WHERE del_flg = 0 AND rank IS NULL";
        $objQuery->query($sql);
    }

    function lfNewsUpdate(&$objQuery){

        if ($_POST["link_method"] == "") {
            $_POST["link_method"] = 1;
        }

        $sql = "UPDATE dtb_news SET news_date = ?, news_title = ?, creator_id = ?, update_date = NOW(),  news_url = ?, link_method = ?, news_comment = ? WHERE news_id = ?";
        $arrRegist = array($this->registDate, $_POST['news_title'], $_SESSION['member_id'], $_POST['news_url'], $_POST["link_method"], $_POST['news_comment'], $_POST['news_id']);

        $objQuery->query($sql, $arrRegist);
    }
}
?>
