#!/usr/local/bin/php
<?php
/**
 * 나이스올더게이트 결제 대사 데이터 수집
 * @date 2019-03-25
 */

require_once 'comp.php';

class compNicepay extends Comp
{
    const PG_NAME = 'nicepay';
    private static $apiUrl = 'https://pg.nicepay.co.kr/desa/NiceFileDownload.jsp';
    private static $aNicepayCompServiceType = array('CD0002', 'BK0002', 'VT0002');
    /**
     * PG 사명 코드 반환
     *
     * @return string
     */
    protected function getPgName()
    {
        return self::PG_NAME;
    }

    protected function getCollectData($sStartTime, $sEndTime)
    {
        $aRawLineData = $this->getCollectDataRequest($sStartTime, $sEndTime);

        $aPgCollectData = array();
        foreach($aRawData as $service){
            // foreach ($aRawLineData as $sLine) {
            foreach ($service as $sLine){    
                // 빈 라인 체크
                if ($this->isBlankLine($sLine) === true) continue;

                // 테이블 컬럼 형태로 변경
                $aMappedData = $this->convertToMappedColumn($sLine, $service);

                if (empty($aMappedData) === false) {
                    array_push($aPgCollectData, $this->convertToComparisonTableData($aMappedData));
                }
            }
        }

        return $aPgCollectData;
    }

    /**
     * key-value 로 맵핑된 배열 return
     *
     * @param $sLine
     * @param $sServiceType
     * @return array
     */
    private function convertToMappedColumn($sLine, $sServiceType) {
        $aLineData = explode("|", $sLine);
        $aMappedData = array();

        // service id가 존재하면 올더게이트 거래데이터
        if (empty($aLineData['2']) === true) {
            return $aMappedData;
        }

        ///////////////////////////////////////////////////////////////////////// data mapping 필요
        if ($sServiceType === 'CD0002') {
            $aMappedData['paymethod']   = 'card';
        } else if ($sServiceType === 'BK0002') {

        } else if ($sServiceType === 'VT0002') {

        }
        $aMappedData['partner_id']  = trim($aLineData['11']);
        $aMappedData['pg_tid']      = substr(trim($aLineData['3']), 0, -1);  /// 원거래 TID   OR   PG에서 부여하는 TID???
        $aMappedData['order_id']    = trim($aLineData['8']);
        $aMappedData['pay_date']    = $this->toDateTimeFormat($aLineData['9']);
        $aMappedData['pay_amount']  = intval($aLineData['5']);
        $aMappedData['pay_state']   = 'T';

        return $aMappedData;
    }

    /**
     * 다날 날짜 포맷을 변경 (다날 결제시간은분 까지만 제공하므로 초단위는 00 으로 통일)
     *
     * @param $sRawDateTime ex) 201901012359
     * @Todo @return string ex) 20190101
     */
    private function toDateTimeFormat($sRawDateTime)
    {
        $sDatetime = substr($sRawDateTime, 0, 4)."-".substr($sRawDateTime, 4, 2)."-".substr($sRawDateTime, 6, 2)." ".substr($sRawDateTime, 8, 2).":".substr($sRawDateTime, 10, 2);
        // 나이스 올더게이트는 년월일(YYYYMMDD)로 데이터를 받기 때문에 시분초는 0으로 초기화
        return $sDatetime.' 00:00:00';
    }

    /**
     * 테이블 맵핑 데이터로 변환
     *
     * @param $aData
     * @return array
     */
    private function convertToComparisonTableData($aData)
    {
        $aTableData = array(
            'pg_name'               => $this->getPgName(),      // pg 사명
            'paymethod'             => $aData['paymethod'],     // 결제수단
            'partner_id'            => $aData['partner_id'],    // 파트너ID(MID)
            'pg_tid'                => $aData['pg_tid'],        // 거래번호(TID)
            'order_id'              => $aData['order_id'],      // 주문번호
            'pay_date'              => $aData['pay_date'],      // 취소일자
            'pay_amount'            => $aData['pay_amount'],    // 결제금액
            'pay_state'             => $aData['pay_state'],     // 거래상태
            'allotment_month'       => '',      // 할부개월수
            'allotment_type'        => 'F',     // 무이자할부구분
            'is_mobile_flag'        => 'F',     // 모바일결제여부
            'card_type'             => '',      // 카드결제(카드계열)
            'member_name'           => '',      // 구매자명
            'bank_name'             => '',      // 은행명
            'bank_acc_no'           => '',      // 가상계좌번호
            'pay_name'              => '',      // 입금자명
            'vir_accont_pay_state'  => 'F',
            'remain_amount'         => 0
        );

        return $aTableData;
    }

    /**
     * trim 했을 시 공백인지
     * @param string $line
     * @return bool
     */
    private function isBlankLine($line) {
        if (trim($line) === '') {
            return true;
        }
        return false;
    }



    /**
     * 대사 데이터 라인 별로 array return
     *
     * @param $sStartTime
     * @param $sEndTime
     * @return array
     */
    private function getCollectDataRequest($sStartTime, $sEndTime)
    {
        $aReqBodyData = $this->getRequestBodyData($sStartTime, $sEndTime);

        $aRawData = array();

        // service구분마다 데이터를 가져와야함 (카드, 계좌이체, 가상계좌)
        // foreach ($aReqBodyData as $reqBodyData) {
        foreach ($aNicepayCompServiceType as $serviceType) {
            $aCURL = array(
                'url' => self::$apiUrl,
                'method' => 'POST',
                // 'data'      => $reqBodyData,
                'data'  => $this->getRequestBodyData($sStartTime, $sEndTime, $serviceType),
            );

            $aResponseResult = utilHttpRequest::req($aCURL, HTTP_REQUEST_TIME);

            // CURL 기본 통신 에러 발생 체크
            if ($aResponseResult['iErrNo'] !== 0) {
                $this->scheduleCommunicationError($aResponseResult['iErrNo']);
                exit;
            }

            // 데이터 행 구분 구분자 \r\n
            $temp = explode(PHP_EOL, $aResponseResult['sResult']);

            /**
             * error response 코드 확인하기
             */
            if (isset($temp[0]) === false || $temp[0] !== '0') {
                $this->scheduleResponseDataError($aResponseResult['sResult']);
                exit;
            }

            // 데이터 인코딩
            $sResData = iconv('euc-kr', 'utf-8//ignore', $aResponseResult['sResult']);
            // $this->parseResponseData($sResData);

            // 0,n-1 index 는 부가 정보이므로 제외
            array_shift($temp);
            array_pop($temp);

            // 개별건 array merge
            // $aRawData = array_merge($aRawData, $temp);
            $aRawData[$serviceType] = $temp;
        }

        return $aRawData;
    }

    /**
     * 대사 데이터 body data setting
     *
     * @param $sStartTime
     * @param $sEndTime
     * @param $sServiceType
     * @return array
     */
    private function getRequestBodyData($sStartTime, $sEndTime, $sServiceType)
    {
        /*
         * 변수  변경해야됨
         * */

        // datetime 에서 숫자를 제외하고 모두 공백으로 치환하고 초단위는 절삭한다.
        $sStartTime = substr(preg_replace('~\D~', '', $sStartTime), 0, 8);
        $sEndTime = substr(preg_replace('~\D~', '', $sEndTime), 0, 8);
        $aEndPoint = array();

        $url = 'type=%s';
        $url .= '&svc=%s';          // 서비스구분
        $url .= '&mid=%s';          // 로그인 ID
        $url .= '&pid=%s';          // 로그인 비밀번호
        $url .= '&fd=%s';           // 거래시작일자
        $url .= '&td=%s';           // 거래종료일자

            // 특수문자 코드표 보고 https://jjipsycho.tistory.com/659 작성하기
        $url = sprintf($url, urlencode('0'), urlencode($sServiceType), urlencode('cafe24npg'), 'cafe24@02', urlencode($sStartTime), urlencode($sEndTime));
        //array_push($aEndPoint, $url);

        // return $aEndPoint;
        return $url;
    }

    /**
     * 수집 데이터 정형화
     * 파싱구분자: | (0x7c)
     * 라인구분자: LF(0x0a)
     *
     * @param string $sResData 나이스올더게이트 정제된지 않은 수집데이터
     */
    private function parseResponseData($sResData) {
       $aRowData = explode('\r\n', $sResData);
       $iRowDataCount = count($aRowData);
        $aTempData = array();

       // key = index, value = data
       foreach ($aRowData as $key => $value) {
           if($key === '0' || $key === ''.$iRowDataCount) {
               continue;
           }

           $aParseData = explode('|', $value);
           $aTempData['partner_id'] = $aParseData['1']; // MID
           $aTempData['pay_date'] = $aParseData['3']; //승인일자
           $aTempData['pg_tid'] = $aParseData['6']; // TID
           $aTempData['pay_amount'] = $aParseData['8']; // 구매금액
           $aTempData['order_id'] = $aParseData['11']; // 주문번호

           var_dump($aTempData);
       }

       /*
        * [0]=>
  string(32) "00|cafe24npga|20190325|20190325|"
  [1]=>
  string(178) "10|APG024345m|CG_mincee80|20190325||APG024345m02011903252421289200|APG024345m02011903252421289200|지니스키니진 외 4건|87120|0|20190325242128212723|20190325-0001822||003||"
  [2]=>
  string(196) "10|APG010520m|CG_yufit12|20190325||APG010520m02011903252421259500|APG010520m02011903252421259500|시그니처 5컬러 브이라인 리프팅밴|43500|0|20190325242125211953|20190325-0000852||088||"
        */
    }
}

$oCompNicepay = new compNicepay();
$oCompNicepay->mainProcess();