import { z } from "zod";
import { ETHIOPIAN_TIN_MESSAGE, isValidEthiopianTin, normalizeEthiopianTin } from "@/lib/tin";

export const companyProfileSchema = z.object({
  company_name: z
    .string()
    .trim()
    .min(2, "Enter the company / organisation name")
    .max(255, "Name is too long"),
  company_tin: z
    .string()
    .trim()
    .transform((v) => normalizeEthiopianTin(v))
    .refine((v) => isValidEthiopianTin(v), ETHIOPIAN_TIN_MESSAGE),
  company_address: z
    .string()
    .trim()
    .min(5, "Enter the company address")
    .max(2000, "Address is too long"),
});

export type CompanyProfileValues = z.infer<typeof companyProfileSchema>;

export const emptyCompanyProfile: CompanyProfileValues = {
  company_name: "",
  company_tin: "",
  company_address: "",
};
