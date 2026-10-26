import {createContext,useContext} from 'react';
export const ReportPresentation=createContext<'admin'|'client'>('admin');
export const useReportPresentation=()=>useContext(ReportPresentation);
