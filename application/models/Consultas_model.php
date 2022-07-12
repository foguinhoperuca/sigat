<?php

defined('BASEPATH') OR exit('No direct script access allowed');

class Consultas_model extends CI_Model {
    public function listaChamados($id_fila = NULL, $id_usuario) {
        /*
         * removida verificacao do solicitante
         */

        $nivel_usuario = $this->db->query('SELECT autorizacao_usuario FROM usuario WHERE id_usuario = ' . $id_usuario)->row()->autorizacao_usuario;
        $final_segment = "";

        if ($nivel_usuario <= 2) {
            $final_segment .= ' (id_usuario_responsavel_chamado = ' . $id_usuario . " OR id_usuario_responsavel_chamado IS NULL) AND";
        }

        if ($id_fila > 0) {
            if ($id_fila == 7) { // fila de Entrega (virtual)
                $final_segment .= " id_fila_chamado = 3 AND entrega_chamado = 1";
            } else {
                $final_segment .= " id_fila_chamado = " . $id_fila;
            }
        } else {
            $final_segment .= " id_fila_chamado > 0 ";
        }

        // var_dump($final_segment . " LALALA " );
        
        // TODO rewrite it!
        $q = <<<SQL
           SELECT
             id_chamado,
             ticket_chamado,
             id_fila_chamado, 
             nome_solicitante_chamado,
             data_chamado,
             -- data_chamado, -- 2x ?? (repete!!)
             prioridade_chamado,
             -- status_chamado, -- sem uso !?
             entrega_chamado,
             -- TODO use otrs instead local
             -- (
             --     SELECT
             --       nome_local
             --     FROM local
             --     WHERE
             --       id_local = id_local_chamado
             -- ) AS nome_local,
             (
                 SELECT
                   l.name AS "nome_local"
                 FROM otrs_locations AS l
                 WHERE
                   pms_id = l.PMSID
             ) AS nome_local,
             (
                 SELECT
                   usuario.nome_usuario
                 FROM usuario
                 WHERE
                   usuario.id_usuario = c.id_usuario_responsavel_chamado
             ) AS nome_responsavel, 
             (
                 SELECT
                   COUNT(*)
                 FROM equipamento_chamado
                 WHERE id_chamado_equipamento = c.id_chamado
             ) AS total_equips,
             (
                 SELECT
                   COUNT(*)
                 FROM equipamento_chamado
                 WHERE id_chamado_equipamento = c.id_chamado AND status_equipamento_chamado IN ('ATENDIDO', 'ENTREGUE', 'INSERVIVEL')
             ) AS atend_equips,
             (
                 SELECT
                   data_interacao
                 FROM interacao
                 WHERE
                   id_chamado_interacao = c.id_chamado
                 ORDER BY
                   data_interacao DESC
                 LIMIT 1
             ) AS data_ultima_interacao
           FROM chamado c
           WHERE
             status_chamado <> 'ENCERRADO'
             AND {$final_segment}
           ;
        SQL;

        return $this->db->query($q)->result();
    }

    /*
     * lista de chamados da fila Suporte Atendimento do OTRS (queue_id = 37)
     */
	public function listaTriagem() {
        $db_otrs = $this->load->database('otrs', TRUE);

        // FIXME Reweite it - not SQL92 compliant query (GROUP BY with outside columns). Postgresql not working neither Mysql without workarround.
        $db_otrs->query("SET SESSION sql_mode=''");
        $res = $db_otrs->query("SELECT t.id, t.tn, t.create_time, t.title, REPLACE(adm.a_from,'\"','') as a_from
        FROM article_data_mime adm
        INNER JOIN article a ON (adm.article_id = a.id)
        INNER JOIN ticket t ON (a.ticket_id = t.id)
        WHERE t.queue_id = " . $this->config->item('queue_id_suporte_atendimento') . " AND t.ticket_state_id IN(1,4)
        GROUP BY t.tn
        ORDER BY adm.create_time ASC");

        return $res->result();
    }
	
	public function buscaTicket($id_ticket,$queue_id) { 
        $dados = array(
            "t_info" => NULL,
            "t_articles" => NULL
        );

        $db_otrs = $this->load->database('otrs', TRUE);

        $res = $db_otrs->query("SELECT t.id, t.tn, t.create_time, t.title, REPLACE(adm.a_from,'\"','') as a_from
        FROM article_data_mime adm
        INNER JOIN article a ON (adm.article_id = a.id)
        INNER JOIN ticket t ON (a.ticket_id = t.id)
        WHERE t.queue_id = ". $queue_id . " AND t.ticket_state_id IN(1,4) AND t.id = " . $id_ticket .
        " ORDER BY adm.create_time asc
        LIMIT 1");

        $dados['t_info'] = $res->row();

        $res = $db_otrs->query("SELECT adm.article_id, REPLACE(adm.a_from,'\"','') as a_from, adm.a_subject, adm.a_body, adm.create_time
        FROM article_data_mime adm
        INNER JOIN article a ON (adm.article_id = a.id)
        INNER JOIN ticket t ON (a.ticket_id = t.id)
        WHERE t.queue_id = ". $queue_id . " AND t.ticket_state_id IN(1,4) AND t.id = " . $id_ticket . 
        " ORDER BY adm.create_time asc");

        $dados['t_articles'] = $res->result();

        return $dados;
    }
    
    
	
	
    public function listaEncerrados() {

        $q = "SELECT id_chamado,ticket_chamado, nome_solicitante_chamado, 
        (SELECT nome_local FROM local WHERE id_local = id_local_chamado) AS nome_local, 
        DATE_FORMAT(data_chamado, \"%d/%m/%Y - %H:%i:%s\") AS data_chamado,
        DATE_FORMAT(data_encerramento_chamado, \"%d/%m/%Y - %H:%i:%s\") as data_encerramento,
        (SELECT usuario.nome_usuario FROM usuario WHERE usuario.id_usuario = chamado.id_usuario_responsavel_chamado) AS nome_responsavel, 
        (SELECT nome_fila FROM fila WHERE id_fila = chamado.id_fila_chamado) AS nome_fila 
        FROM chamado
        WHERE status_chamado = 'ENCERRADO'";

        return $this->db->query($q)->result();
    }

    public function listaFilas() {
        
        $this->db->select();
        $this->db->from('fila');
        $this->db->where('status_fila = \'ATIVO\'');
        return $this->db->get()->result_array();
        
    }

    public function listaFila($id_fila) {
        
        $this->db->select();
        $this->db->from('fila');
        $this->db->where("id_fila = " . $id_fila);
        return $this->db->get()->row();
        
    }
    
    

    public function listaLocais() {
        
        $this->db->select();
        $this->db->from('local');
        $this->db->order_by('nome_local');
        return $this->db->get()->result_array();
        
    }

    public function buscaGrupo($auto) {

        $this->db->select();
        $this->db->from('grupo');
        $this->db->where('autorizacao_grupo = ' . $auto);
        return $this->db->get()->row();


    }
    

    public function buscaRapida($termo) {

        $result = NULL;

        if (strlen($termo) >= 3) {

            $result = array();
            
            $equip = NULL;

            $this->db->select();
            $this->db->from("v_equipamento");
            $this->db->where("num_equip like '%" . $termo ."%'");
            $this->db->or_where("desc_equip like '%" . $termo ."%'");
            $this->db->limit(10);
            $equip = $this->db->get()->result_array();

            $result["equip"] = count($equip) > 0 ? $equip : array();

            $this->db->select();
            $this->db->from("v_chamado");
            $this->db->where("ticket like '%" . $termo ."%'");
            $this->db->or_where("nome_solicitante like '%" . $termo ."%'");
            $this->db->or_where("nome_local like '%" . $termo ."%'");
            $this->db->or_where("id like '%" . $termo ."%'");
            $this->db->limit(10); 
            $chamado = $this->db->get()->result_array();

            $result["chamado"] = count($chamado) > 0 ?  $chamado : array();
               

            $this->db->select();
            $this->db->from("v_triagem");
            $this->db->where("ticket like '%" . $termo ."%'");
            $this->db->or_where("nome_solicitante like '%" . $termo ."%'");
            $this->db->limit(10); 
            $triagem = $this->db->get()->result_array();
            
            $result["triagem"] = count($triagem) > 0 ? $triagem : array();

        }

        return $result;
    }

    public function temEquipEspera($id_chamado) {

        $out = 0;

        $this->db->select("status_equipamento_chamado");
        $this->db->from("equipamento_chamado");
        $this->db->where("status_equipamento_chamado =  'ESPERA'");
        $this->db->where("id_chamado_equipamento = " . $id_chamado);

        $out = $this->db->get()->num_rows();

        return $out;
    }

    public function conf() {
        $this->db->select();
        $this->db->from('configuracao');
        
        return $this->db->get()->row();
    }
    
    
}

?>