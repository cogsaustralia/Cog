<?php
declare(strict_types=1);

/**
 * SubTrustBExecutionService
 * Members Asset Pool Unit Trust Deed — Sub-Trust A
 * Electronic Transactions Act 1999 (Cth) + s.14G ETA 2000 (NSW)
 */
class SubTrustBExecutionService
{
    public const DEED_KEY       = 'sub_trust_b';
    public const DEED_TITLE     = 'COGS OF AUSTRALIA FOUNDATION DIVIDEND DISTRIBUTION UNIT TRUST DEED';
    public const DEED_VERSION   = 'Sub-Trust B';
    public const DEED_PDF       = 'COGS_SubTrustB.pdf';
    public const DEED_SHA256    = 'fd3e9e184fcd19242184a15b4196d255b048e8ffb894c3d8a70158df9c447a5c';
    public const EXECUTION_DATE = '2026-04-21';
    public const EXECUTOR_NAME    = 'Thomas Boyd Cunliffe';
    public const EXECUTOR_ADDRESS = '780 Sugarbag Road West, DRAKE 2469 NSW';
    public const WITNESS_NAME       = 'Alexander Stefan Gorshenin';
    public const WITNESS_DOB        = '1979-05-16';
    public const WITNESS_ADDRESS    = '1/118 Ridgeway Ave, Southport QLD 4215';
    public const WITNESS_OCCUPATION = 'Independent witness';
    public const ATTESTATION_METHOD =
        'Electronic attestation via audio-visual link — section 14G Electronic Transactions Act 2000 (NSW)';
    public const DECLARANT_ACKNOWLEDGEMENT =
        "I, Thomas Boyd Cunliffe, execute this Deed as Declarant of Sub-Trust B. "
        . "I declare that Sub-Trust A is a ring-fenced division of the CJVM Hybrid Trust "
        . "constituted by this Deed as a sub-trust instrument under the Declaration. "
        . "The Initial Trust Property is described in the Declaration and in Schedule 1 of this Deed. "
        . "I execute this instrument electronically in accordance with the Electronic Transactions Act 1999 (Cth) "
        . "and section 14G of the Electronic Transactions Act 2000 (NSW). "
        . "No wet-ink signature or paper counterpart is required or produced.";
    public const TRUSTEE_ACKNOWLEDGEMENT =
        "I, Thomas Boyd Cunliffe, execute this Deed as Caretaker Trustee of Sub-Trust B of the "
        . "COGS of Australia Foundation Community Joint Venture Mainspring Hybrid Trust. "
        . "I accept the office of Caretaker Trustee of Sub-Trust B and undertake to hold and administer "
        . "the trust property in accordance with this Deed, the Declaration, and the Joint Venture Participation Agreement. "
        . "I execute this instrument electronically in accordance with the Electronic Transactions Act 1999 (Cth) "
        . "and section 14G of the Electronic Transactions Act 2000 (NSW). "
        . "No wet-ink signature or paper counterpart is required or produced.";
    public const WITNESS_ATTESTATION_TEXT =
        "I, Alexander Stefan Gorshenin, attest that I observed Thomas Boyd Cunliffe "
        . "execute the COGS of Australia Foundation Dividend Distribution Unit Trust Deed (Sub-Trust B) "
        . "electronically via audio-visual link on 21 April 2026. "
        . "I am satisfied this is the same document executed. "
        . "This attestation is given electronically under section 14G of the "
        . "Electronic Transactions Act 2000 (NSW).";

    public static function recordExecution(PDO $db, string $capacity, string $sessionId, string $ipAddress, string $userAgent): array
    {
        if (!in_array($capacity, ['declarant','caretaker_trustee'], true)) throw new \InvalidArgumentException("Invalid capacity: {$capacity}");
        $chk = $db->prepare('SELECT record_id FROM declaration_execution_records WHERE session_id=? AND capacity=? AND deed_key=? LIMIT 1');
        $chk->execute([$sessionId, $capacity, self::DEED_KEY]);
        if ($chk->fetch()) throw new \RuntimeException("Execution record for '{$capacity}' already exists.");
        $recordId = self::uuid4();
        $nowUtc   = gmdate('Y-m-d H:i:s.').sprintf('%03d',(int)(microtime(true)*1000)%1000);
        $nowDb    = gmdate('Y-m-d H:i:s');
        $ipData   = json_encode(['ip_address'=>$ipAddress,'user_agent'=>$userAgent,'server_time_utc'=>$nowDb],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $ipHash   = hash('sha256',$ipData);
        $ackText  = $capacity==='declarant' ? self::DECLARANT_ACKNOWLEDGEMENT : self::TRUSTEE_ACKNOWLEDGEMENT;
        $canonical = ['record_id'=>$recordId,'session_id'=>$sessionId,'capacity'=>$capacity,'executor_full_name'=>self::EXECUTOR_NAME,'executor_address'=>self::EXECUTOR_ADDRESS,'deed_key'=>self::DEED_KEY,'deed_version'=>self::DEED_VERSION,'execution_date'=>self::EXECUTION_DATE,'deed_sha256'=>self::DEED_SHA256,'execution_timestamp_utc'=>$nowUtc,'ip_device_hash'=>$ipHash,'acceptance_flag_engaged'=>true,'acknowledgement_text'=>$ackText];
        $recHash  = hash('sha256',json_encode($canonical,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO declaration_execution_records (record_id,session_id,capacity,executor_full_name,executor_address,deed_key,deed_title,deed_version,execution_date,deed_sha256,execution_timestamp_utc,ip_device_hash,ip_device_data,acceptance_flag_engaged,execution_method,witness_required,record_sha256,onchain_commitment_txid,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,\'Electronic — Electronic Transactions Act 1999 (Cth) and Electronic Transactions Act 2000 (NSW)\',1,?,NULL,\'executor_complete\',?)')->execute([$recordId,$sessionId,$capacity,self::EXECUTOR_NAME,self::EXECUTOR_ADDRESS,self::DEED_KEY,self::DEED_TITLE,self::DEED_VERSION,self::EXECUTION_DATE,self::DEED_SHA256,$nowUtc,$ipHash,$ipData,$recHash,$nowDb]);
            $db->prepare('INSERT INTO evidence_vault_entries (entry_type,subject_type,subject_id,subject_ref,payload_hash,payload_summary,source_system,chain_tx_hash,created_by_type,created_at) VALUES (\'declaration_execution\',\'deed\',0,?,?,?,\'declaration_execution\',?,\'system\',?)')->execute([$recordId,$recHash,sprintf('Sub-Trust B execution — capacity: %s',$capacity),'0x'.$recHash,$nowDb]);
            $eveId=(int)$db->lastInsertId();
            $db->prepare('UPDATE declaration_execution_records SET onchain_commitment_txid=? WHERE record_id=?')->execute([(string)$eveId,$recordId]);
            $dvaChk=$db->prepare('SELECT id FROM deed_version_anchors WHERE deed_key=? LIMIT 1');
            $dvaChk->execute([self::DEED_KEY]);
            if(!$dvaChk->fetch()) $db->prepare('INSERT INTO deed_version_anchors (deed_key,deed_title,deed_version,execution_date,deed_sha256,pdf_filename,session_id,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([self::DEED_KEY,self::DEED_TITLE,self::DEED_VERSION,self::EXECUTION_DATE,self::DEED_SHA256,self::DEED_PDF,$sessionId,$nowDb]);
            $db->commit();
        } catch(\Throwable $e){ if($db->inTransaction()) $db->rollBack(); throw $e; }
        return ['record_id'=>$recordId,'session_id'=>$sessionId,'capacity'=>$capacity,'deed_sha256'=>self::DEED_SHA256,'record_sha256'=>$recHash,'execution_timestamp_utc'=>$nowUtc,'onchain_commitment_txid'=>(string)$eveId,'status'=>'executor_complete'];
    }

    public static function recordWitnessAttestation(PDO $db, string $sessionId, string $ipAddress, string $userAgent): array
    {
        $stmt=$db->prepare('SELECT capacity FROM declaration_execution_records WHERE session_id=? AND deed_key=?');
        $stmt->execute([$sessionId,self::DEED_KEY]);
        $caps=array_column($stmt->fetchAll(\PDO::FETCH_ASSOC),'capacity');
        if(!in_array('declarant',$caps)||!in_array('caretaker_trustee',$caps)) throw new \RuntimeException('Both executor capacity records must be complete before witness attestation.');
        $dup=$db->prepare('SELECT attestation_id FROM declaration_witness_attestations WHERE session_id=? LIMIT 1');
        $dup->execute([$sessionId]);
        if($dup->fetch()) throw new \RuntimeException('Witness attestation already exists for this session.');
        $attId=self::uuid4();
        $nowUtc=gmdate('Y-m-d H:i:s.').sprintf('%03d',(int)(microtime(true)*1000)%1000);
        $nowDb=gmdate('Y-m-d H:i:s');
        $ipData=json_encode(['ip_address'=>$ipAddress,'user_agent'=>$userAgent,'server_time_utc'=>$nowDb],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $ipHash=hash('sha256',$ipData);
        $canonical=['attestation_id'=>$attId,'session_id'=>$sessionId,'witness_full_name'=>self::WITNESS_NAME,'witness_dob'=>self::WITNESS_DOB,'witness_address'=>self::WITNESS_ADDRESS,'deed_key'=>self::DEED_KEY,'deed_sha256'=>self::DEED_SHA256,'attestation_timestamp_utc'=>$nowUtc,'ip_device_hash'=>$ipHash,'attestation_flag_engaged'=>true,'attestation_text'=>self::WITNESS_ATTESTATION_TEXT];
        $recHash=hash('sha256',json_encode($canonical,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO declaration_witness_attestations (attestation_id,session_id,witness_full_name,witness_dob,witness_address,witness_occupation,attestation_method,deed_key,deed_sha256,attestation_timestamp_utc,ip_device_hash,ip_device_data,attestation_flag_engaged,attestation_text,record_sha256,onchain_commitment_txid,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,NULL,?)')->execute([$attId,$sessionId,self::WITNESS_NAME,self::WITNESS_DOB,self::WITNESS_ADDRESS,self::WITNESS_OCCUPATION,self::ATTESTATION_METHOD,self::DEED_KEY,self::DEED_SHA256,$nowUtc,$ipHash,$ipData,self::WITNESS_ATTESTATION_TEXT,$recHash,$nowDb]);
            $eveId=(int)$db->lastInsertId();
            $db->prepare('INSERT INTO evidence_vault_entries (entry_type,subject_type,subject_id,subject_ref,payload_hash,payload_summary,source_system,chain_tx_hash,created_by_type,created_at) VALUES (\'witness_attestation\',\'deed\',0,?,?,?,\'witness_attestation\',?,\'system\',?)')->execute([$attId,$recHash,sprintf('Witness attestation — Sub-Trust B — witness: %s',self::WITNESS_NAME),'0x'.$recHash,$nowDb]);
            $db->prepare('UPDATE declaration_witness_attestations SET onchain_commitment_txid=? WHERE attestation_id=?')->execute([(string)$eveId,$attId]);
            $db->prepare('UPDATE declaration_execution_records SET witness_attestation_id=?,status=\'fully_executed\' WHERE session_id=? AND deed_key=?')->execute([$attId,$sessionId,self::DEED_KEY]);
            $db->commit();
        } catch(\Throwable $e){ if($db->inTransaction()) $db->rollBack(); throw $e; }
        return ['attestation_id'=>$attId,'session_id'=>$sessionId,'deed_sha256'=>self::DEED_SHA256,'attestation_timestamp_utc'=>$nowUtc,'record_sha256'=>$recHash,'onchain_commitment_txid'=>(string)$eveId];
    }

    public static function getSession(PDO $db, string $sessionId): ?array
    {
        try {
            $s=$db->prepare('SELECT record_id,capacity,deed_sha256,record_sha256,execution_timestamp_utc,onchain_commitment_txid,status FROM declaration_execution_records WHERE session_id=? AND deed_key=? ORDER BY capacity');
            $s->execute([$sessionId,self::DEED_KEY]);
            $records=$s->fetchAll(\PDO::FETCH_ASSOC);
            if(!$records) return null;
            $a=$db->prepare('SELECT attestation_id,witness_full_name,attestation_timestamp_utc,record_sha256,onchain_commitment_txid FROM declaration_witness_attestations WHERE session_id=? LIMIT 1');
            $a->execute([$sessionId]);
            return ['records'=>$records,'attestation'=>$a->fetch(\PDO::FETCH_ASSOC)?:null,'session_id'=>$sessionId];
        } catch(\Throwable $e){ return null; }
    }

    public static function getActiveSession(PDO $db): ?array
    {
        try {
            $s=$db->prepare('SELECT DISTINCT session_id FROM declaration_execution_records WHERE deed_key=? ORDER BY created_at DESC LIMIT 1');
            $s->execute([self::DEED_KEY]);
            $row=$s->fetch(\PDO::FETCH_ASSOC);
            return $row ? self::getSession($db,(string)$row['session_id']) : null;
        } catch(\Throwable $e){ return null; }
    }

    public static function generateOneTimeToken(PDO $db, string $purpose): string
    {
        $allowed=['sub_trust_b_execution','sub_trust_b_witness'];
        if(!in_array($purpose,$allowed,true)) throw new \InvalidArgumentException("Invalid purpose: {$purpose}");
        $s=$db->prepare('SELECT id FROM one_time_tokens WHERE purpose=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1');
        $s->execute([$purpose]);
        if($s->fetch()) throw new \RuntimeException("Valid unused {$purpose} token exists.");
        $raw=bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO one_time_tokens (token_hash,purpose,expires_at,created_at) VALUES (?,?,?,UTC_TIMESTAMP())')->execute([hash('sha256',$raw),$purpose,gmdate('Y-m-d H:i:s',strtotime('+24 hours'))]);
        return $raw;
    }

    public static function validateOneTimeToken(PDO $db, string $raw, string $purpose): bool
    {
        $s=$db->prepare('SELECT id FROM one_time_tokens WHERE token_hash=? AND purpose=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1');
        $s->execute([hash('sha256',$raw),$purpose]);
        $row=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$row) return false;
        $db->prepare('UPDATE one_time_tokens SET used_at=UTC_TIMESTAMP() WHERE id=?')->execute([(int)$row['id']]);
        return true;
    }

    private static function uuid4(): string
    {
        $d=random_bytes(16); $d[6]=chr((ord($d[6])&0x0f)|0x40); $d[8]=chr((ord($d[8])&0x3f)|0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
    }
}
